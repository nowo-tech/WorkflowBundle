<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Model;

use Nowo\WorkflowBundle\Model\WorkflowActor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class WorkflowActorTest extends TestCase
{
    #[Test]
    public function itHoldsTheIdentifierRolesAndImpersonator(): void
    {
        $user  = new InMemoryUser('carl', null);
        $actor = new WorkflowActor('carl', [3 => 'ROLE_USER', 7 => 'IS_IMPERSONATOR'], $user, 'admin');

        self::assertSame('carl', $actor->userIdentifier);
        self::assertSame(['ROLE_USER', 'IS_IMPERSONATOR'], $actor->roles);
        self::assertTrue($actor->hasRole('ROLE_USER'));
        self::assertFalse($actor->hasRole('ROLE_ADMIN'));
        self::assertTrue($actor->isImpersonated());
        self::assertSame('admin', $actor->impersonatorIdentifier);
        self::assertSame($user, $actor->user());
    }

    #[Test]
    public function aPlainActorIsNotImpersonatedAndACopyHasNoUser(): void
    {
        $actor = new WorkflowActor('alice', [], new InMemoryUser('alice', null));

        self::assertFalse($actor->isImpersonated());
        self::assertSame([], $actor->roles);
        self::assertNull((clone $actor)->user());
        self::assertNull((new WorkflowActor('bob'))->user());
    }
}
