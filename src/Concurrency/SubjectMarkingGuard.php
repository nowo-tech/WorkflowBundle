<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Concurrency;

use BackedEnum;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Exception\WorkflowConflictException;
use Stringable;

use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function is_array;
use function is_scalar;
use function sort;
use function sprintf;

/**
 * Expected-marking compare-and-set of a Doctrine entity subject (since 1.20, REQ-RUNTIME-003): re-reads the subject's
 * marking column straight from the database (a scalar DQL read: the identity map and the subject are never touched),
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

        $original = $manager->getUnitOfWork()->getOriginalEntityData($subject);
        $expected = self::places(array_key_exists($property, $original) ? $original[$property] : $metadata->getFieldValue($subject, $property));

        $builder = $manager->createQueryBuilder()
            ->select(sprintf('s.%s AS marking', $property))
            ->from($metadata->getName(), 's');
        $index = 0;
        foreach ($manager->getUnitOfWork()->getEntityIdentifier($subject) as $field => $value) {
            $parameter = 'id' . $index++;
            $builder->andWhere(sprintf('s.%s = :%s', $field, $parameter))
                ->setParameter($parameter, $value, $metadata->hasField($field) ? $metadata->getTypeOfField($field) : null);
        }
        $query = $builder->getQuery();
        if ($lock) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }
        $rows = $query->getScalarResult();

        if ($rows === []) {
            throw WorkflowConflictException::subjectMissing($definition->getSlug(), $expected, $retryAfterSeconds);
        }
        $current = self::places($rows[0]['marking'] ?? null);
        if ($current !== $expected) {
            throw WorkflowConflictException::markingChanged($definition->getSlug(), $expected, $current, $retryAfterSeconds);
        }

        return true;
    }

    /**
     * The places of a stored marking value: a place name (single state), a place => count map (multiple state), a list
     * of places, or a backed enum; sorted.
     *
     * @return list<string>
     */
    public static function places(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if ($value instanceof BackedEnum) {
            return [(string) $value->value];
        }
        if (is_array($value)) {
            $places = array_map(static fn (mixed $place): string => is_scalar($place) || $place instanceof Stringable ? (string) $place : '', array_is_list($value) ? $value : array_keys($value));
            sort($places);

            return $places;
        }

        return [is_scalar($value) || $value instanceof Stringable ? (string) $value : ''];
    }
}
