<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Events;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Exception\WorkflowNameCollisionException;
use Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry;
use Nowo\WorkflowBundle\Tests\Support\GuardProbeListener;
use Nowo\WorkflowBundle\Tests\Support\GuardProbeSubject;
use Nowo\WorkflowBundle\Tests\Support\WorkflowEventsKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

use function sys_get_temp_dir;

/**
 * KR-W-I3: a host #[AsEventListener] guard reaches a database workflow in a booted kernel, and a slug equal to a
 * framework.workflows name is refused (KR-W-L3).
 */
final class WorkflowEventsKernelTest extends TestCase
{
    private ?WorkflowEventsKernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . WorkflowEventsKernel::CACHE_ROOT);
    }

    /** Removed once: a booted container keeps autoloading its lazy-ghost classes from its cache directory. */
    public static function tearDownAfterClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . WorkflowEventsKernel::CACHE_ROOT);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    public function testAutoconfiguredGuardListenerBlocksTheDatabaseWorkflow(): void
    {
        $container = $this->bootedContainer();
        $workflow  = $this->registry($container)->createWorkflow($this->definition('kr_probe'));
        $listener  = $container->get(GuardProbeListener::class);
        self::assertInstanceOf(GuardProbeListener::class, $listener);

        self::assertFalse($workflow->can(new GuardProbeSubject(), 'finish'));
        self::assertSame(1, $listener->calls);
    }

    public function testFrameworkWorkflowNamesAreCollected(): void
    {
        self::assertSame([WorkflowEventsKernel::FRAMEWORK_WORKFLOW], $this->bootedContainer()->getParameter('nowo_workflow.framework_workflow_names'));
    }

    public function testSlugEqualToAFrameworkWorkflowIsRefused(): void
    {
        $registry = $this->registry($this->bootedContainer());

        $this->expectException(WorkflowNameCollisionException::class);
        $registry->createWorkflow($this->definition(WorkflowEventsKernel::FRAMEWORK_WORKFLOW));
    }

    private function bootedContainer(): ContainerInterface
    {
        if (!$this->kernel instanceof WorkflowEventsKernel) {
            $this->kernel = new WorkflowEventsKernel();
            $this->kernel->boot();
        }

        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    private function registry(ContainerInterface $container): DatabaseWorkflowRegistry
    {
        $registry = $container->get(DatabaseWorkflowRegistry::class);
        self::assertInstanceOf(DatabaseWorkflowRegistry::class, $registry);

        return $registry;
    }

    private function definition(string $slug): WorkflowDefinition
    {
        $definition = new WorkflowDefinition('Probe', $slug, 'draft', GuardProbeSubject::class);
        $definition->addPlace(new WorkflowPlace('draft', null, 0));
        $definition->addPlace(new WorkflowPlace('done', null, 1));
        $definition->addTransition(new WorkflowTransition('finish', ['draft'], ['done']));

        return $definition;
    }
}
