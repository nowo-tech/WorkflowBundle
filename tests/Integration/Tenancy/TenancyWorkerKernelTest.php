<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Tenancy;

use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Service\DatabaseMetadataStore;
use Nowo\WorkflowBundle\Tests\Integration\Tenancy\Domain\LeaveRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function gc_collect_cycles;
use function memory_get_usage;
use function str_contains;
use function sys_get_temp_dir;

/**
 * FrankenPHP worker mode with kernel reset false (REQ-RUNTIME-002): one kernel, no services resetter, 1,000 rounds
 * alternating two organizations. Each round sees only its organization's definitions (UI list and runtime), and
 * memory stays flat after the warm-up.
 */
final class TenancyWorkerKernelTest extends TestCase
{
    use TenancyKernelSupport;

    private const ROUNDS = 1000;

    private const WARM_UP = 100;

    private const MEMORY_MARGIN = 2 * 1024 * 1024;

    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . TenancyTestKernel::CACHE_ROOT);
    }

    #[Test]
    public function alternatingTenantsNeverSeeEachOthersDefinitions(): void
    {
        $kernel = self::boot('tenancy');
        try {
            $global = self::seedLeaveTemplate($kernel);
            $own    = self::seedLeaveTemplate($kernel, 'days <= 1', new WorkflowTenant('org-a'));
            $own->setName('Leave A only');
            self::repository($kernel)->save($own);
            $baseline = 0;

            for ($i = 1; $i <= self::ROUNDS; ++$i) {
                $organization = $i % 2 === 0 ? 'org-a' : 'org-b';
                $list         = (string) self::send($kernel, self::ui($organization, '/definitions'), 'admin-' . $organization)->getContent();
                self::assertSame($organization === 'org-a', str_contains($list, 'Leave A only'), 'list #' . $i);

                $workflow = self::registry($kernel)->forSubject(new LeaveRequest('leave-' . $i, $organization, 3), 'leave');
                self::assertSame(($organization === 'org-a' ? $own : $global)->getId(), $workflow->getMetadataStore()->getWorkflowMetadata()[DatabaseMetadataStore::DEFINITION_ID], 'runtime #' . $i);

                if ($i === self::WARM_UP) {
                    gc_collect_cycles();
                    $baseline = memory_get_usage();
                }
            }

            gc_collect_cycles();
            self::assertLessThan(self::MEMORY_MARGIN, memory_get_usage() - $baseline, 'memory growth after the warm-up');
        } finally {
            $kernel->shutdown();
        }
    }
}
