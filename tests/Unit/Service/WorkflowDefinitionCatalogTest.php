<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Service;

use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use LogicException;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Model\WorkflowDefinitionPage;
use Nowo\WorkflowBundle\Model\WorkflowDefinitionView;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\WorkflowDefinitionCatalog;
use Nowo\WorkflowBundle\Service\WorkflowVersioning;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * 2.1: the catalog's reads page the repository's listing and answer views and references; the install writes once, is
 * a no-op when the tenant owns the slug, and takes the winner's definition when the unique key refuses its write. The
 * real store (private manager, two connections) is covered by DefinitionCatalogKernelTest and ConcurrentInstallTest.
 */
final class WorkflowDefinitionCatalogTest extends TestCase
{
    #[Test]
    public function theListPagesTheRepositoryListingAndClampsThePage(): void
    {
        $tenant     = new WorkflowTenant('org-a');
        $repository = $this->repository();
        $repository->method('tenantListing')->willReturn([
            ['id' => 5, 'slug' => 'a', 'version' => 1, 'name' => 'A'],
            ['id' => 6, 'slug' => 'b', 'version' => 2, 'name' => 'B'],
            ['id' => 7, 'slug' => 'c', 'version' => 1, 'name' => 'C'],
        ]);
        $repository->method('hydrate')->willReturnCallback(fn (array $ids): array => array_map(fn (int $id): WorkflowDefinition => $this->stored($tenant, $id, 1), $ids));
        $catalog = $this->catalog($repository);

        $first = $catalog->list($tenant, 1, 2);
        self::assertSame([5, 6], array_map(static fn (WorkflowDefinitionView $view): int => $view->ref->id, $first->items));
        self::assertSame([3, 2, 1, 2], [$first->total, $first->pages, $first->page, $first->pageSize]);

        $past = $catalog->list($tenant, 9, 2);
        self::assertSame([7], array_map(static fn (WorkflowDefinitionView $view): int => $view->ref->id, $past->items));
        self::assertSame(2, $past->page);

        $tiny = $catalog->list($tenant, -4, 0);
        self::assertSame([1, 1, 3], [$tiny->page, $tiny->pageSize, $tiny->pages]);
        self::assertCount(1, $tiny->items);
        self::assertSame(3, $catalog->count($tenant));
    }

    #[Test]
    public function aTenantWithoutDefinitionsGetsAnEmptyPage(): void
    {
        $repository = $this->repository();
        $repository->method('tenantListing')->willReturn([]);
        $repository->method('hydrate')->willReturn([]);
        $page = $this->catalog($repository)->list(new WorkflowTenant('org-a'));

        self::assertSame([], $page->items);
        self::assertSame([0, 1, 1, 50], [$page->total, $page->pages, $page->page, $page->pageSize]);
        self::assertEquals(WorkflowDefinitionPage::empty(50), $page);
    }

    #[Test]
    public function thePageRefusesNonsenseCounters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new WorkflowDefinitionPage([], -1, 1, 1, 1);
    }

    #[Test]
    public function theReferencesCarryTheIdAndTheTenant(): void
    {
        $tenant     = new WorkflowTenant('org-a');
        $repository = $this->repository();
        $repository->method('tenantListing')->willReturn([['id' => 5, 'slug' => 'a', 'version' => 3, 'name' => 'A']]);
        $references = $this->catalog($repository)->references($tenant);

        self::assertCount(1, $references);
        self::assertSame([5, 'a', 3], [$references[0]->id, $references[0]->slug, $references[0]->version]);
        self::assertEquals($tenant, $references[0]->tenant);
    }

    #[Test]
    public function isInstalledAsksTheScopeAndNeverForAnEmptyKey(): void
    {
        $tenant     = new WorkflowTenant('org-a');
        $repository = $this->repository();
        $repository->expects(self::once())->method('existsInScope')->with('leave', $tenant)->willReturn(true);
        $catalog = $this->catalog($repository);

        self::assertTrue($catalog->isInstalled('leave', $tenant));
        self::assertFalse($catalog->isInstalled('', $tenant));
    }

    #[Test]
    public function anInstallWhenTheTenantOwnsTheSlugWritesNothing(): void
    {
        $tenant     = new WorkflowTenant('org-a');
        $repository = $this->repository();
        $repository->method('findVersions')->willReturn([$this->stored($tenant, 9, 2), $this->stored($tenant, 8, 1)]);
        $repository->expects(self::never())->method('save');
        $result = $this->catalog($repository)->installTemplate('leave', $tenant);

        self::assertFalse($result->created);
        self::assertSame(9, $result->definition->ref->id);
    }

    #[Test]
    public function aFirstInstallClonesTheTemplateOnce(): void
    {
        $tenant     = new WorkflowTenant('org-a');
        $repository = $this->repository();
        $repository->method('findVersions')->willReturn([]);
        $repository->method('findCurrent')->with('leave', null)->willReturn($this->stored(null, 1, 1));
        $repository->method('existsInScope')->willReturn(false);
        $repository->expects(self::once())->method('save')->willReturnCallback(static function (WorkflowDefinition $copy): void {
            (new ReflectionProperty(WorkflowDefinition::class, 'id'))->setValue($copy, 31);
        });
        $result = $this->catalog($repository)->installTemplate('leave', $tenant, publish: true);

        self::assertTrue($result->created);
        self::assertTrue($result->definition->isPublished());
        self::assertSame(31, $result->definition->ref->id);
        self::assertEquals($tenant, $result->definition->ref->tenant);
    }

    #[Test]
    public function aMissingTemplateAndAnEmptyKeyAreNotFound(): void
    {
        $repository = $this->repository();
        $repository->method('findVersions')->willReturn([]);
        $repository->method('findCurrent')->willReturn(null);
        $repository->expects(self::never())->method('save');
        $catalog = $this->catalog($repository);

        foreach (['missing', ''] as $key) {
            try {
                $catalog->installTemplate($key, new WorkflowTenant('org-a'));
                self::fail('Not found.');
            } catch (WorkflowNotFoundException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function whenTheUniqueKeyRefusesTheWriteTheWinnersDefinitionIsAnswered(): void
    {
        $tenant     = new WorkflowTenant('org-a');
        $winner     = $this->stored($tenant, 12, 1);
        $repository = $this->repository();
        $repository->method('findVersions')->willReturnOnConsecutiveCalls([], [$winner]);
        $repository->method('findCurrent')->willReturn($this->stored(null, 1, 1));
        $repository->method('existsInScope')->willReturn(false);
        $repository->method('save')->willThrowException(new UniqueConstraintViolationException($this->createStub(DriverException::class), null));
        $result = $this->catalog($repository)->installTemplate('leave', $tenant);

        self::assertFalse($result->created);
        self::assertSame(12, $result->definition->ref->id);
    }

    #[Test]
    public function aRefusalThatLeavesNoDefinitionBehindIsRethrown(): void
    {
        $tenant     = new WorkflowTenant('org-a');
        $repository = $this->repository();
        $repository->method('findVersions')->willReturn([]);
        $repository->method('findCurrent')->willReturn($this->stored(null, 1, 1));
        $repository->method('existsInScope')->willReturn(false);
        $repository->method('save')->willThrowException(new UniqueConstraintViolationException($this->createStub(DriverException::class), null));

        $this->expectException(LogicException::class);
        $this->catalog($repository)->installTemplate('leave', $tenant);
    }

    /**
     * @return MockObject&WorkflowDefinitionRepository
     */
    private function repository(): MockObject
    {
        return $this->createMock(WorkflowDefinitionRepository::class);
    }

    private function catalog(WorkflowDefinitionRepository $repository): WorkflowDefinitionCatalog
    {
        return new WorkflowDefinitionCatalog(new WorkflowVersioning($repository), $repository);
    }

    private function stored(?WorkflowTenant $tenant, int $id, int $version): WorkflowDefinition
    {
        $definition = new WorkflowDefinition('Leave approval', 'leave', 'requested', 'App\\Entity\\LeaveRequest');
        $definition->addPlace(new WorkflowPlace('requested'));
        $definition->addPlace(new WorkflowPlace('approved'));
        $definition->addTransition(new WorkflowTransition('approve', ['requested'], ['approved']));
        if ($tenant instanceof WorkflowTenant) {
            $definition->startAsDraft($tenant)->publish();
        }
        (new ReflectionProperty(WorkflowDefinition::class, 'id'))->setValue($definition, $id);
        (new ReflectionProperty(WorkflowDefinition::class, 'version'))->setValue($definition, $version);

        return $definition;
    }
}
