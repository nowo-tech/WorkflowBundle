<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Concurrency;

use BackedEnum;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Utility\IdentifierFlattener;
use Doctrine\ORM\Utility\PersisterHelper;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Exception\WorkflowConflictException;
use Stringable;

use function array_key_exists;
use function array_keys;
use function array_map;
use function is_array;
use function is_int;
use function is_numeric;
use function is_scalar;
use function is_string;
use function ksort;
use function strval;

use const SORT_STRING;

/**
 * Expected-marking compare-and-set of a Doctrine entity subject (since 1.20, REQ-RUNTIME-003): re-reads the subject's
 * marking column straight from the database (plain SQL: the identity map and the subject are never touched),
 * with `SELECT … FOR UPDATE` when asked, and refuses when it is not the marking this request loaded (the unit of
 * work's snapshot of the subject).
 *
 * Stateless: safe as a shared service in a long-running worker.
 *
 * @internal
 */
final readonly class SubjectMarkingGuard
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    /**
     * The open entity manager that holds the subject as a persisted entity (the subject class's manager, else the
     * applicator's), or null: not an entity, not managed, or a new entity not inserted yet (no row to race on).
     */
    public function managerOf(object $subject): ?EntityManagerInterface
    {
        $candidates = [];
        $manager    = $this->managerRegistry?->getManagerForClass($subject::class);
        if ($manager instanceof EntityManagerInterface) {
            $candidates[] = $manager;
        }
        $candidates[] = $this->entityManager;

        foreach ($candidates as $candidate) {
            if ($candidate->isOpen() && $candidate->contains($subject) && !$candidate->getUnitOfWork()->isScheduledForInsert($subject)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Whether the subject's class has a Doctrine `#[Version]` field.
     */
    public function isVersioned(EntityManagerInterface $manager, object $subject): bool
    {
        return $manager->getClassMetadata($subject::class)->isVersioned;
    }

    /**
     * Refuses when the subject's marking in the database is not the one this request loaded. False when the marking
     * property is not a mapped field of the subject (nothing to compare: no control).
     *
     * The column is read with plain SQL on the table that declares it (JOINED inheritance: never through the outer
     * joins of the subclasses, which PostgreSQL refuses to lock), filtered by the identifier columns, and converted
     * with the field's Doctrine type (a JSON marking compares as the decoded place map). A proxy subject is initialised
     * first, so its snapshot is the loaded row.
     *
     * @param bool $lock Read with `SELECT … FOR UPDATE` (a transaction must be active)
     *
     * @throws WorkflowConflictException when the marking changed or the row is gone
     */
    public function assertUnchanged(EntityManagerInterface $manager, object $subject, WorkflowDefinition $definition, bool $lock, ?int $retryAfterSeconds): bool
    {
        $metadata = $manager->getClassMetadata($subject::class);
        $property = $definition->getMarkingProperty();
        if (!$metadata->hasField($property)) {
            return false;
        }
        $manager->initializeObject($subject);

        $original = $manager->getUnitOfWork()->getOriginalEntityData($subject);
        $expected = self::marking(array_key_exists($property, $original) ? $original[$property] : $metadata->getFieldValue($subject, $property));

        $mapping    = $metadata->getFieldMapping($property);
        $declaring  = $manager->getClassMetadata($mapping->inherited ?? $metadata->getName());
        $connection = $manager->getConnection();
        $platform   = $connection->getDatabasePlatform();
        $quoting    = $manager->getConfiguration()->getQuoteStrategy();

        $query = $connection->createQueryBuilder()
            ->select($quoting->getColumnName($property, $declaring, $platform))
            ->from($quoting->getTableName($declaring, $platform));
        $identifier = (new IdentifierFlattener($manager->getUnitOfWork(), $manager->getMetadataFactory()))
            ->flattenIdentifier($metadata, $manager->getUnitOfWork()->getEntityIdentifier($subject));
        foreach ($identifier as $field => $value) {
            $column = $metadata->hasField($field) ? $quoting->getColumnName($field, $declaring, $platform) : $metadata->getSingleAssociationJoinColumnName($field);
            $query->andWhere($column . ' = ' . $query->createNamedParameter($value, PersisterHelper::getTypeOfField($field, $metadata, $manager)[0] ?? ParameterType::STRING));
        }
        // SQLite has no row locks (its writers are serialised on the database file).
        if ($lock && !$platform instanceof SQLitePlatform) {
            $query->forUpdate();
        }
        $rows = $query->executeQuery()->fetchFirstColumn();

        if ($rows === []) {
            throw WorkflowConflictException::subjectMissing($definition->getSlug(), self::names($expected), $retryAfterSeconds);
        }
        $value = Type::getType($mapping->type)->convertToPHPValue($rows[0], $platform);
        if ($mapping->enumType !== null && (is_int($value) || is_string($value))) {
            $value = $mapping->enumType::tryFrom($value) ?? $value;
        }
        $current = self::marking($value);
        if ($current !== $expected) {
            throw WorkflowConflictException::markingChanged($definition->getSlug(), self::names($expected), self::names($current), $retryAfterSeconds);
        }

        return true;
    }

    /**
     * The places of a stored marking value, sorted (see {@see self::marking()}).
     *
     * @return list<string>
     */
    public static function places(mixed $value): array
    {
        return self::names(self::marking($value));
    }

    /**
     * A stored marking value as a place => token count map sorted by place, the shape of Symfony's MethodMarkingStore:
     * a place name (single state) or a place => count map (multiple state; its keys are the places, also "0" or "1"),
     * or a backed enum.
     *
     * @return array<string, int>
     */
    public static function marking(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if ($value instanceof BackedEnum) {
            return [(string) $value->value => 1];
        }
        if (!is_array($value)) {
            return [self::name($value) => 1];
        }
        $marking = [];
        foreach ($value as $place => $tokens) {
            $marking[(string) $place] = is_int($tokens) || (is_string($tokens) && is_numeric($tokens)) ? (int) $tokens : 1;
        }
        ksort($marking, SORT_STRING);

        return $marking;
    }

    /**
     * @param array<string, int> $marking
     *
     * @return list<string>
     */
    private static function names(array $marking): array
    {
        return array_map(strval(...), array_keys($marking));
    }

    private static function name(mixed $value): string
    {
        return is_scalar($value) || $value instanceof Stringable ? (string) $value : '';
    }
}
