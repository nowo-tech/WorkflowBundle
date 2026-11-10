<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\GuardEvent;

/**
 * Host guard registered through #[AsEventListener] autoconfiguration (WorkflowEventsKernel).
 */
final class GuardProbeListener
{
    public int $calls = 0;

    #[AsEventListener('workflow.kr_probe.guard.finish')]
    public function guardFinish(GuardEvent $event): void
    {
        ++$this->calls;
        $event->setBlocked(true, 'Blocked by the host guard.');
    }
}
