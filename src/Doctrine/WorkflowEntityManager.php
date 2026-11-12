<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Doctrine;

use Closure;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Filter\Parameter;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\EventListener\WorkflowUnitOfWorkSubscriber;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

use function array_keys;
use function array_sum;
use function is_string;
use function ksort;
use function sprintf;

/**
 * The bundle's private entity manager for the workflow definitions (1.14.0, REQ-ARCH-001 rule 6).
 *
 * Before 1.14.0 the admin UI and the demo seeder wrote the definitions through the application's default entity
 * manager, so saving a definition flushed every change the application had left pending, and a failed save reset the
 * application's manager. The bundle now writes the definitions through a **private** manager created on the
 * connection, configuration (mapping, metadata cache, proxies, repository factory) and event manager (table prefix,
 * host listeners) of the source manager: `doctrine.entity_manager` when set, else the application's default manager
 * (where the bundle registers its mapping; the same manager {@see PrivateEntityManagerPass} attaches the rollback
 * middleware to, audit NF-3). The source must map {@see WorkflowDefinition}. Its unit of work holds only definition
 * rows: a bundle flush never commits an application change, and a failed write closes only this manager. The tables
 * do not move. The connection is shared, so a transaction the application opened around a bundle call includes the
 * bundle's rows.
 *
 * The object is a stable decorator (the repository keeps it); the wrapped manager is resolved on every call and
 * created again when:
 *
 * - it was closed (its own failed flush) or the source connection changed;
 * - a rollback (transaction or savepoint) happened on the connection since it last wrote, so its identity map may
 *   describe rows that no longer exist ({@see PrivateEntityManagerMiddleware}). A stale manager that still has
 *   scheduled inserts, updates or deletes is **kept** (replacing it would silently drop them) until that work is
 *   flushed or the next unit-of-work boundary;
 * - {@see reset()} ran: at every outermost unit-of-work boundary ({@see WorkflowUnitOfWorkSubscriber}) and at
 *   `kernel.reset`. Changes still pending at that moment are dropped and logged as a warning (audit NF-2: a host that
 *   edits a definition and flushes its own manager instead of calling `WorkflowDefinitionRepository::save()`).
 *
 * The SQL filters enabled on the source manager, with their parameters, are mirrored on the private manager on every
 * call, and a filter whose parameters changed is re-created, so a parameter the source no longer has never survives.
 *
 * @internal
 */
final class WorkflowEntityManager extends EntityManagerDecorator implements ResetInterface
{
    private ?EntityManagerInterface $current = null;

    /** A rollback happened on the connection since the private manager was created or last replaced. */
    private bool $stale = false;

    /**
     * @param ManagerRegistry $registry The application's Doctrine registry (the source manager is resolved per call)
     * @param string|null $sourceManagerName `doctrine.entity_manager` (null = the default manager)
     * @param LoggerInterface|null $logger warns when a boundary drops pending changes
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ?string $sourceManagerName = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        // No wrapped manager yet: every read of $this->wrapped goes through __get() (resolved per call).
        unset($this->{'wrapped'});
    }

    /**
     * The wrapped manager of {@see EntityManagerDecorator} (the only magic property).
     */
    public function __get(string $name): EntityManagerInterface
    {
        if ($name !== 'wrapped') {
            throw new LogicException(sprintf('Undefined property %s::$%s.', self::class, $name));
        }

        return $this->live();
    }

    /**
     * The open private manager, created (again) when needed, with the source's SQL filters.
     */
    public function live(): EntityManagerInterface
    {
        $source  = $this->source();
        $current = $this->current;
        if (!$current instanceof EntityManagerInterface || !$current->isOpen() || $current->getConnection() !== $source->getConnection()
            || ($this->stale && !$this->hasScheduledWork($current))) {
            if ($current instanceof EntityManagerInterface && $current->isOpen()) {
                $current->clear();
            }
            // DoctrineBundle's ContainerAwareEventManager extends EventManager; any other implementation gets a fresh one.
            $eventManager = $source->getEventManager();
            // @igor-ignore - Bundle-owned unit of work: dropped at every boundary, replaced when closed, stale or on another connection.
            $current = $this->current = new EntityManager($source->getConnection(), $source->getConfiguration(), $eventManager instanceof EventManager ? $eventManager : null);
            // @igor-ignore - Bundle-owned flag of the private manager, reset when the manager is replaced.
            $this->stale = false;
        }
        $this->syncFilters($source, $current);

        return $current;
    }

    /**
     * Whether a private manager exists right now (no side effect; tests and the boundary subscriber).
     */
    public function isCreated(): bool
    {
        return $this->current instanceof EntityManagerInterface;
    }

    /**
     * Whether a rollback made the private manager's identity map stale (no side effect; tests).
     */
    public function isStale(): bool
    {
        return $this->stale;
    }

    /**
     * A transaction or savepoint was rolled back on the connection (told by {@see PrivateEntityManagerConnection}).
     * The manager is replaced at its next use unless it has scheduled work; it is never cleared here (the rollback
     * may run inside the private manager's own failing flush).
     */
    public function connectionRolledBack(): void
    {
        if ($this->current instanceof EntityManagerInterface) {
            // @igor-ignore - Bundle-owned flag of the private manager, cleared when the manager is replaced.
            $this->stale = true;
        }
    }

    /**
     * Detaches every row from the private manager. Never touches an application manager and never creates the private
     * one.
     */
    public function clear(): void
    {
        if ($this->current instanceof EntityManagerInterface && $this->current->isOpen()) {
            $this->current->clear();
        }
    }

    /**
     * Drops the private manager (a unit-of-work boundary or `kernel.reset`); the next call creates a fresh one.
     * Changes still pending are lost: they are logged as a warning with their entity classes and counts.
     */
    public function reset(): void
    {
        $this->warnAboutDroppedChanges();
        $this->clear();
        // @igor-ignore - Boundary drop of the bundle's own manager (no state kept).
        $this->current = null;
        // @igor-ignore - Boundary drop of the bundle's own manager (no state kept).
        $this->stale = false;
    }

    /**
     * The application's manager whose connection, configuration, events and filters the private one shares. Never
     * flushed, cleared, closed or reset by the bundle.
     *
     * @throws LogicException when the source is not an ORM manager or does not map the bundle entities
     */
    public function source(): EntityManagerInterface
    {
        $source = $this->registry->getManager($this->sourceManagerName);
        if (!$source instanceof EntityManagerInterface || $source->getMetadataFactory()->isTransient(WorkflowDefinition::class)) {
            throw new LogicException(sprintf('The Doctrine entity manager "%s" does not map %s: keep the bundle mapping on the default manager, or set nowo_workflow.doctrine.entity_manager to the manager that maps it (see docs/CONFIGURATION.md, "Doctrine").', $this->sourceManagerName ?? $this->registry->getDefaultManagerName(), WorkflowDefinition::class));
        }

        return $source;
    }

    /**
     * Audit NF-2 safety net: a boundary that drops the private manager while it still has inserts, updates or deletes
     * waiting (a definition edited and never saved through the bundle) logs them, per entity class.
     */
    private function warnAboutDroppedChanges(): void
    {
        if (!$this->logger instanceof LoggerInterface || !$this->current instanceof EntityManagerInterface || !$this->current->isOpen()) {
            return;
        }

        $unitOfWork = $this->current->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        $changes = [];
        foreach ([$unitOfWork->getScheduledEntityInsertions(), $unitOfWork->getScheduledEntityUpdates(), $unitOfWork->getScheduledEntityDeletions()] as $entities) {
            foreach ($entities as $entity) {
                $class           = WorkflowManagerRegistry::realClass($entity::class);
                $changes[$class] = ($changes[$class] ?? 0) + 1;
            }
        }
        if ($changes === []) {
            return;
        }

        ksort($changes);
        $this->logger->warning('Workflow definitions: {count} pending change(s) of the bundle\'s private entity manager were dropped at a unit-of-work boundary. Save definitions with WorkflowDefinitionRepository::save(); the application\'s flush() does not write them.', [
            'bundle'  => 'NowoWorkflowBundle',
            'count'   => array_sum($changes),
            'changes' => $changes,
        ]);
    }

    /**
     * Scheduled inserts, updates or deletes, including changes not computed yet (a managed entity edited in memory).
     */
    private function hasScheduledWork(EntityManagerInterface $manager): bool
    {
        $unitOfWork = $manager->getUnitOfWork();
        $unitOfWork->computeChangeSets();

        return $unitOfWork->getScheduledEntityInsertions() !== []
            || $unitOfWork->getScheduledEntityUpdates() !== []
            || $unitOfWork->getScheduledEntityDeletions() !== []
            || $unitOfWork->getScheduledCollectionUpdates() !== []
            || $unitOfWork->getScheduledCollectionDeletions() !== [];
    }

    /**
     * Mirrors the SQL filters enabled on the source manager, with their parameters, on the private one, and disables
     * the others. A filter whose parameters differ (its state string) is disabled and enabled again (a fresh instance)
     * before its parameters are copied, so a parameter the source no longer has (a previous tenant) never survives.
     * The parameters are read as they are stored (audit NF-7): values, list flag and the list type are kept.
     */
    private function syncFilters(EntityManagerInterface $source, EntityManagerInterface $target): void
    {
        $wanted  = $source->getFilters()->getEnabledFilters();
        $filters = $target->getFilters();
        foreach (array_keys($filters->getEnabledFilters()) as $name) {
            if (!isset($wanted[$name])) {
                $filters->disable($name);
            }
        }
        foreach ($wanted as $name => $filter) {
            $state = (string) $filter;
            if ($filters->isEnabled($name)) {
                if ((string) $filters->getFilter($name) === $state) {
                    continue;
                }
                $filters->disable($name);
            }
            $copy = $filters->enable($name);
            foreach ($this->filterParameters($filter) as $parameter => $value) {
                if ($value->isList) {
                    /** @var array<mixed> $list */
                    $list = $value->value;
                    $copy->setParameterList($parameter, $list, is_string($value->type) ? $value->type : Types::STRING);
                } else {
                    // SQLFilter::setParameter() takes a string type or null: a non-string type was inferred, null infers it again.
                    $copy->setParameter($parameter, $value->value, is_string($value->type) ? $value->type : null);
                }
            }
        }
    }

    /**
     * The raw parameters of a filter (SQLFilter keeps them private and only exposes quoted values).
     *
     * @return array<string, Parameter>
     */
    private function filterParameters(SQLFilter $filter): array
    {
        $read = static fn (SQLFilter $filter): array => $filter->parameters; // @phpstan-ignore property.private (bound to SQLFilter below)
        /** @var array<string, Parameter> $parameters */
        $parameters = Closure::bind($read, null, SQLFilter::class)($filter);

        return $parameters;
    }
}
