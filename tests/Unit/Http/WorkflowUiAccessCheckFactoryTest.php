<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Http;

use Nowo\WorkflowBundle\Http\WorkflowUiAccessCheckFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class WorkflowUiAccessCheckFactoryTest extends TestCase
{
    #[Test]
    public function itCopiesTheRequestAndTheUser(): void
    {
        $request = Request::create('/workflow/definitions/7?secret=REDACTED-TEST', 'POST', [], ['session' => 'REDACTED-TEST']);
        $request->attributes->set('_route', 'nowo_workflow_definition_delete');
        $request->attributes->set('_controller', 'Ctrl::delete');
        $request->attributes->set('_route_params', ['id' => '7']);
        $user  = new InMemoryUser('alice', null, ['ROLE_USER']);
        $check = WorkflowUiAccessCheckFactory::fromRequest($request, new UsernamePasswordToken($user, 'main', ['ROLE_USER']));

        self::assertSame('POST', $check->method);
        self::assertSame('nowo_workflow_definition_delete', $check->routeName);
        self::assertSame('Ctrl::delete', $check->controller);
        self::assertSame('/workflow/definitions/7', $check->path());
        self::assertSame(['id' => '7'], $check->routeParams());
        self::assertNotNull($check->actor);
        self::assertSame('alice', $check->actor->userIdentifier);
        self::assertSame(['ROLE_USER'], $check->actor->roles);
        self::assertSame($user, $check->actor->user());
        self::assertFalse($check->actor->isImpersonated());
    }

    #[Test]
    public function nonStringAttributesAndMissingTokensGiveNulls(): void
    {
        $request = Request::create('/workflow');
        $request->attributes->set('_route', 42);
        $request->attributes->set('_controller', static fn (): null => null);
        $request->attributes->set('_route_params', 'nope');

        $check = WorkflowUiAccessCheckFactory::fromRequest($request);
        self::assertNull($check->routeName);
        self::assertNull($check->controller);
        self::assertSame([], $check->routeParams());
        self::assertNull($check->actor);

        self::assertNull(WorkflowUiAccessCheckFactory::actor(new NullToken()));
    }

    #[Test]
    public function aSwitchUserTokenNamesTheImpersonator(): void
    {
        $original = new UsernamePasswordToken(new InMemoryUser('admin', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']);
        $actor    = WorkflowUiAccessCheckFactory::actor(new SwitchUserToken(new InMemoryUser('carl', null), 'main', ['ROLE_USER'], $original));

        self::assertNotNull($actor);
        self::assertSame('carl', $actor->userIdentifier);
        self::assertSame('admin', $actor->impersonatorIdentifier);
    }
}
