<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Removed\Discovered\Ui;

use Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;

/**
 * A 1.x host decorator of the removed UI access port, registered by resource discovery with `#[AsDecorator]` (audit
 * XR-WF-M1). It cannot be loaded: the build must fail instead of dropping it, which would widen access. Excluded from
 * static analysis (phpstan.neon.dist, rector.php).
 */
#[AsDecorator(WorkflowUiAccessCheckerInterface::class)]
final class RestrictingUiAccessDecorator implements WorkflowUiAccessCheckerInterface
{
    public function __construct(#[AutowireDecorated] private readonly WorkflowUiAccessCheckerInterface $inner)
    {
    }

    public function isGranted(Request $request): bool
    {
        return $request->getMethod() === 'GET' && $this->inner->isGranted($request);
    }
}
