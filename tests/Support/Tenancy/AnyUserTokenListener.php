<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Tenancy;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Authenticates the user named in the X-Test-User header with ROLE_ADMIN (the 1.14 access policy grants; the
 * tenancy action checker decides). No header: anonymous.
 */
final readonly class AnyUserTokenListener implements EventSubscriberInterface
{
    public const HEADER = 'X-Test-User';

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
        $this->tokens->setToken($name === '' ? null : new UsernamePasswordToken(new InMemoryUser($name, null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN']));
    }
}
