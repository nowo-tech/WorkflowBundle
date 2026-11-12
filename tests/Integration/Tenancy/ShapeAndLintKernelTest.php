<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Tenancy;

use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Exception\WorkflowInvalidShapeException;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

use function sys_get_temp_dir;

/**
 * 1.15.2: transitions FrameworkBundle would refuse are listed by nowo:workflow:lint and never published.
 */
final class ShapeAndLintKernelTest extends TestCase
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

    #[Test]
    public function anInvalidShapeIsListedByTheLintAndNeverPublished(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        self::seedLeaveTemplate($kernel);
        $lint = new CommandTester((new Application($kernel))->find('nowo:workflow:lint'));

        self::assertSame(Command::SUCCESS, $lint->execute([]));
        self::assertStringContainsString('1 definition(s) checked: every transition is valid.', $lint->getDisplay());

        $draft = self::versioning($kernel)->cloneTemplate('leave', new WorkflowTenant('org-a'));
        $draft->addTransition(new WorkflowTransition('fork', ['submitted'], ['approved', 'rejected']));
        self::repository($kernel)->save($draft);
        $id = (int) $draft->getId();

        self::assertSame(Command::FAILURE, $lint->execute([]));
        self::assertStringContainsString('Transition "fork": a state machine transition has exactly one target place.', $lint->getDisplay());
        self::assertStringContainsString('org-a', $lint->getDisplay());

        $publish = self::send($kernel, self::ui('org-a', '/definitions/' . $id . '/publish'), 'admin-org-a', 'POST', ['_token' => self::csrf($kernel, 'publish' . $id)]);
        self::assertSame(302, $publish->getStatusCode());
        self::assertStringEndsWith('/definitions/' . $id, (string) $publish->headers->get('Location'));
        $reloaded = self::repository($kernel)->find($id);
        self::assertTrue($reloaded?->isDraft(), 'not published');

        $this->expectException(WorkflowInvalidShapeException::class);
        $this->expectExceptionMessage('Workflow "leave" has invalid transitions: Transition "fork"');
        self::versioning($kernel)->publish($reloaded);
    }
}
