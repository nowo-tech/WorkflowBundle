<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Model;

use Nowo\WorkflowBundle\Model\WorkflowActor;
use Nowo\WorkflowBundle\Model\WorkflowUiAccessCheck;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function json_encode;
use function print_r;
use function serialize;
use function unserialize;
use function var_export;

final class WorkflowUiAccessCheckTest extends TestCase
{
    #[Test]
    public function itExposesTheRequestFactsAndKeepsOnlyScalarRouteParameters(): void
    {
        $actor = new WorkflowActor('alice', ['ROLE_USER']);
        $check = new WorkflowUiAccessCheck('POST', 'nowo_workflow_definition_delete', 'Ctrl::delete', $actor, '/workflow/definitions/7/delete', [
            'id'   => '7',
            'n'    => 3,
            'f'    => 1.5,
            'b'    => true,
            'null' => null,
            'obj'  => new stdClass(),
            'list' => ['x'],
            0      => 'positional',
        ]);

        self::assertSame('POST', $check->method);
        self::assertSame('nowo_workflow_definition_delete', $check->routeName);
        self::assertSame('Ctrl::delete', $check->controller);
        self::assertSame($actor, $check->actor);
        self::assertTrue($check->isAuthenticated());
        self::assertSame('/workflow/definitions/7/delete', $check->path());
        self::assertSame('7', $check->routeParam('id'));
        self::assertSame(3, $check->routeParam('n'));
        self::assertSame(1.5, $check->routeParam('f'));
        self::assertTrue($check->routeParam('b'));
        self::assertTrue($check->hasRouteParam('null'));
        self::assertNull($check->routeParam('null'));
        self::assertFalse($check->hasRouteParam('obj'));
        self::assertFalse($check->hasRouteParam('list'));
        self::assertNull($check->routeParam('missing'));
        self::assertSame(['id' => '7', 'n' => 3, 'f' => 1.5, 'b' => true, 'null' => null], $check->routeParams());
    }

    #[Test]
    public function anAnonymousCheckHasNoActor(): void
    {
        $check = new WorkflowUiAccessCheck('GET');

        self::assertFalse($check->isAuthenticated());
        self::assertNull($check->routeName);
        self::assertSame('', $check->path());
        self::assertSame([], $check->routeParams());
    }

    #[Test]
    public function noDumpShowsThePathTheRouteParametersOrTheUser(): void
    {
        $user  = new InMemoryUser('alice', 'secret-hash-REDACTED-TEST', ['ROLE_USER']);
        $check = new WorkflowUiAccessCheck('GET', 'r', null, new WorkflowActor('alice', ['ROLE_USER'], $user), '/workflow/share/tok-REDACTED-TEST', ['token' => 'tok-REDACTED-TEST']);

        foreach ([var_export($check, true), print_r($check, true), (string) json_encode($check), serialize($check), print_r((array) $check, true)] as $dump) {
            self::assertStringNotContainsString('tok-REDACTED-TEST', $dump);
            self::assertStringNotContainsString('secret-hash-REDACTED-TEST', $dump);
        }

        $copy = clone $check;
        self::assertSame('', $copy->path());
        self::assertSame([], $copy->routeParams());

        $restored = unserialize(serialize($check));
        self::assertInstanceOf(WorkflowUiAccessCheck::class, $restored);
        self::assertSame('', $restored->path());
        self::assertNull($restored->actor?->user());
    }
}
