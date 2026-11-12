<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Access;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Stands in for a firewall in the test kernels: sets (or clears) the security token from the `X-Test-User` header on
 * every main request, so consecutive requests in one kernel alternate users.
 */
final readonly class TestTokenListener implements EventSubscriberInterface
{
    public const HEADER = 'X-Test-User';

    /** @var array<string, list<string>> */
    public const USERS = ['alice' => ['ROLE_USER'], 'admin' => ['ROLE_ADMIN'], 'bob' => ['ROLE_EDITOR', 'ROLE_USER']];

    public function __construct(private TokenStorageInterface $tokens)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 8192]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $name = (string) $event->getRequest()->headers->get(self::HEADER, '');
        if ($name === 'admin-as-alice') {
            $admin = new UsernamePasswordToken(new InMemoryUser('admin', null, self::USERS['admin']), 'main', self::USERS['admin']);
            $this->tokens->setToken(new SwitchUserToken(new InMemoryUser('alice', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN'], $admin));

            return;
        }

        $roles = self::USERS[$name] ?? null;
        $this->tokens->setToken($roles === null ? null : new UsernamePasswordToken(new InMemoryUser($name, null, $roles), 'main', $roles));
    }
}
