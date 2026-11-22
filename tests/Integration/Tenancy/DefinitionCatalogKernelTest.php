<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Tenancy;

use Nowo\WorkflowBundle\Contract\WorkflowDefinitionCatalogInterface;
use Nowo\WorkflowBundle\Exception\WorkflowNotFoundException;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Service\WorkflowDefinitionCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function sys_get_temp_dir;

/**
 * 2.1 (DDD-host checklist B7): the catalog port lists a tenant's definitions and installs a template idempotently, with
 * views and references only, through the bundle's private manager (host shapes with and without tenancy resolvers).
 */
final class DefinitionCatalogKernelTest extends TestCase
{
    use TenancyKernelSupport;

    private ?TenancyTestKernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . TenancyTestKernel::CACHE_ROOT);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shapes(): iterable
    {
        yield 'tenancy' => ['tenancy'];
        yield 'DDD host, tenancy' => ['tenancy-ddd'];
        yield 'no tenancy' => ['plain'];
    }

    #[Test]
    #[DataProvider('shapes')]
    public function thePortIsTheBuiltInService(string $variant): void
    {
        $this->kernel = $kernel = self::boot($variant);

        self::assertInstanceOf(WorkflowDefinitionCatalog::class, $this->catalog($kernel));
    }

    #[Test]
    public function installingIsIdempotentAndListingShowsViewsOfTheExactTenant(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        self::seedLeaveTemplate($kernel);
        $catalog = $this->catalog($kernel);
        $orgA    = new WorkflowTenant('org-a');

        self::assertSame(0, $catalog->count($orgA));
        self::assertFalse($catalog->isInstalled('leave', $orgA));
        self::assertSame([], $catalog->references($orgA));
        self::assertSame([], $catalog->list($orgA)->items);

        $first = $catalog->installTemplate('leave', $orgA, publish: true);
        self::assertTrue($first->created);
        self::assertTrue($first->definition->isPublished());
        self::assertEquals($orgA, $first->definition->ref->tenant);
        self::assertSame(1, $first->definition->ref->version);

        $again = $catalog->installTemplate('leave', $orgA);
        self::assertFalse($again->created);
        self::assertSame($first->definition->ref->id, $again->definition->ref->id);
        self::assertSame(1, $catalog->count($orgA));
        self::assertTrue($catalog->isInstalled('leave', $orgA));

        $page = $catalog->list($orgA);
        self::assertSame(1, $page->total);
        self::assertSame(1, $page->pages);
        self::assertCount(1, $page->items);
        self::assertSame($first->definition->ref->id, $page->items[0]->ref->id);
        self::assertEqualsCanonicalizing(['requested', 'submitted', 'approved', 'rejected'], $page->items[0]->placeNames);
        self::assertEquals([$first->definition->ref], $catalog->references($orgA));
    }

    #[Test]
    public function tenantsAreIsolatedAndKeysAreExact(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        self::seedLeaveTemplate($kernel);
        $catalog = $this->catalog($kernel);

        $catalog->installTemplate('leave', new WorkflowTenant('org-a'));

        foreach (['org-b', 'Org-A', 'org-a2'] as $other) {
            $tenant = new WorkflowTenant($other);
            self::assertSame(0, $catalog->count($tenant), $other);
            self::assertFalse($catalog->isInstalled('leave', $tenant), $other);
            self::assertSame([], $catalog->list($tenant)->items, $other);
        }
        self::assertFalse($catalog->isInstalled('Leave', new WorkflowTenant('org-a')), 'the template key is exact too');

        self::assertTrue($catalog->installTemplate('leave', new WorkflowTenant('Org-A'))->created, 'a case variant is another tenant');
        self::assertSame(1, $catalog->count(new WorkflowTenant('org-a')));
        self::assertSame(1, $catalog->count(new WorkflowTenant('Org-A')));
    }

    #[Test]
    public function aMissingTemplateIsRefusedAndAnEmptyKeyToo(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        $catalog      = $this->catalog($kernel);

        foreach (['missing', ''] as $key) {
            try {
                $catalog->installTemplate($key, new WorkflowTenant('org-a'));
                self::fail('There is no template "' . $key . '".');
            } catch (WorkflowNotFoundException) {
                self::assertSame(0, $catalog->count(new WorkflowTenant('org-a')));
            }
        }
        self::assertFalse($catalog->isInstalled('', new WorkflowTenant('org-a')));
    }

    #[Test]
    public function aRetiredCloneCountsAsInstalledAndIsNotInstalledAgain(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        self::seedLeaveTemplate($kernel);
        $catalog = $this->catalog($kernel);
        $orgA    = new WorkflowTenant('org-a');

        $clone = $catalog->installTemplate('leave', $orgA, publish: true);
        self::versionManager($kernel)->retire($clone->definition->ref);

        $again = $catalog->installTemplate('leave', $orgA);
        self::assertFalse($again->created);
        self::assertTrue($again->definition->isRetired());
        self::assertSame(1, $catalog->count($orgA));
    }

    #[Test]
    public function theListPagesByNameWithTheLatestVersionOfEachSlug(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        self::seedLeaveTemplate($kernel);
        $catalog = $this->catalog($kernel);
        $orgA    = new WorkflowTenant('org-a');

        $clone = $catalog->installTemplate('leave', $orgA, publish: true);
        $draft = self::versionManager($kernel)->createDraftOf($clone->definition->ref);

        $page = $catalog->list($orgA, 1, 10);
        self::assertSame(1, $page->total, 'one row per slug');
        self::assertSame($draft->ref->id, $page->items[0]->ref->id, 'the latest version, here the open draft');

        self::assertCount(1, $catalog->list($orgA, 1, 1)->items, 'page 1 holds the only definition');
        $clamped = $catalog->list($orgA, 99, 0);
        self::assertSame(1, $clamped->page);
        self::assertSame(1, $clamped->pageSize);
        self::assertCount(1, $clamped->items);
    }

    private function catalog(TenancyTestKernel $kernel): WorkflowDefinitionCatalogInterface
    {
        return self::service($kernel, WorkflowDefinitionCatalogInterface::class, 'test.' . WorkflowDefinitionCatalogInterface::class);
    }
}
