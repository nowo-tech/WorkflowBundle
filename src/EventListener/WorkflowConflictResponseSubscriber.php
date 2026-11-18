<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\EventListener;

use Doctrine\ORM\OptimisticLockException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\WriteUnit\LockConflictExceptionInterface;
use Nowo\WorkflowBundle\Exception\WorkflowConflictException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * Since 1.20 (REQ-RUNTIME-003 rule 6): on the bundle's own routes (the Workflow UI), a lock conflict the client may
 * retry answers 409 + `Retry-After` with the code `CONFLICT_001` instead of a 500: a WorkflowConflictException, any
 * LockConflictExceptionInterface or TransientStorageFailure, a Doctrine OptimisticLockException, or a deadlock / lock
 * wait timeout / serialization failure (MySQL 1213 / 1205, MariaDB 1020, PostgreSQL 40001 / 40P01 / 55P03) anywhere in
 * the previous chain. The bundle's writes are one flush each, so
 * nothing was committed when they fail.
 *
 * JSON (XHR or JSON requests): `{"error": "CONFLICT_001", "message": "…"}`. HTML: the exception becomes an
 * HttpException with that status and headers, so the application's own (branded) error page renders it. Other
 * failures, and every route of the application, are left alone (a host with nowo-tech/transaction-doctrine's
 * responder answers WorkflowConflictException on its routes as well).
 *
 * Stateless (FrankenPHP worker, kernel reset off).
 *
 * @internal
 */
final readonly class WorkflowConflictResponseSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private int $retryAfterSeconds = 1,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Before the framework's error listener (-128) and the host's mappers (0).
        return [KernelEvents::EXCEPTION => ['onKernelException', 64]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $failure = $event->getThrowable();
        if (!WorkflowUiAccessSubscriber::isWorkflowUiRoute($request) || !self::isConflict($failure)) {
            return;
        }

        $headers = [
            'Retry-After'   => (string) $this->retryAfter($failure),
            'Cache-Control' => 'no-store, private',
        ];
        if ($this->wantsJson($request)) {
            $event->setResponse(new JsonResponse(
                ['error' => WorkflowConflictException::CODE, 'message' => WorkflowConflictException::MESSAGE],
                Response::HTTP_CONFLICT,
                $headers,
            ));

            return;
        }

        $event->setThrowable(new HttpException(Response::HTTP_CONFLICT, WorkflowConflictException::MESSAGE, $failure, $headers + ['X-Error-Code' => WorkflowConflictException::CODE]));
    }

    public static function isConflict(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof LockConflictExceptionInterface
                || $current instanceof TransientStorageFailure
                || $current instanceof OptimisticLockException) {
                return true;
            }
        }

        return (new TransientFailureClassifier())->isTransient($failure);
    }

    private function retryAfter(Throwable $failure): int
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure && $current->retryAfterSeconds() !== null) {
                return $current->retryAfterSeconds();
            }
        }

        return $this->retryAfterSeconds;
    }

    private function wantsJson(Request $request): bool
    {
        return $request->isXmlHttpRequest()
            || $request->getContentTypeFormat() === 'json'
            || $request->getPreferredFormat(null) === 'json'
            || $request->getRequestFormat(null) === 'json';
    }
}
