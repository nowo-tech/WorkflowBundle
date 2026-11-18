<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\EventListener;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\OptimisticLockException;
use LogicException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\WorkflowBundle\EventListener\WorkflowConflictResponseSubscriber;
use Nowo\WorkflowBundle\Exception\WorkflowConflictException;
use Nowo\WorkflowBundle\Exception\WorkflowPinConflictException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

final class WorkflowConflictResponseSubscriberTest extends TestCase
{
    #[Test]
    public function itRunsBeforeTheFrameworkErrorListener(): void
    {
        self::assertSame([KernelEvents::EXCEPTION => ['onKernelException', 64]], WorkflowConflictResponseSubscriber::getSubscribedEvents());
    }

    #[Test]
    public function aJsonRequestGets409WithRetryAfterAndTheCode(): void
    {
        $request = $this->request();
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $event = $this->event($request, WorkflowConflictException::markingChanged('leave', ['a'], ['b'], 4));

        (new WorkflowConflictResponseSubscriber())->onKernelException($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('4', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('{"error":"CONFLICT_001","message":"' . WorkflowConflictException::MESSAGE . '"}', $response->getContent());
    }

    #[Test]
    public function anHtmlRequestGetsA409HttpExceptionForTheApplicationErrorPage(): void
    {
        $failure = new RuntimeException('flush failed', 0, new OptimisticLockException('stale', null));
        $event   = $this->event($this->request(), $failure);

        (new WorkflowConflictResponseSubscriber(2))->onKernelException($event);

        self::assertNull($event->getResponse());
        $http = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $http);
        self::assertSame(409, $http->getStatusCode());
        self::assertSame('2', $http->getHeaders()['Retry-After']);
        self::assertSame('CONFLICT_001', $http->getHeaders()['X-Error-Code']);
        self::assertSame($failure, $http->getPrevious());
    }

    #[Test]
    public function aJsonFormatAndATransientFailureWithoutHintUseTheConfiguredRetryAfter(): void
    {
        foreach (['json-content', 'json-format', 'json-accept'] as $kind) {
            $request = $this->request();
            match ($kind) {
                'json-content' => $request->headers->set('Content-Type', 'application/json'),
                'json-format'  => $request->setRequestFormat('json'),
                'json-accept'  => $request->headers->set('Accept', 'application/json'),
            };
            $event = $this->event($request, new TransientStorageFailure());

            (new WorkflowConflictResponseSubscriber(5))->onKernelException($event);

            self::assertSame(409, $event->getResponse()?->getStatusCode(), $kind);
            self::assertSame('5', $event->getResponse()->headers->get('Retry-After'), $kind);
        }
    }

    #[Test]
    public function aDeadlockOfTheDriverIsAConflict(): void
    {
        self::assertTrue(WorkflowConflictResponseSubscriber::isConflict(new RuntimeException('x', 0, new DeadlockException(new class('deadlock', '40001', 1213) extends AbstractException {}, null))));
        self::assertTrue(WorkflowConflictResponseSubscriber::isConflict(WorkflowPinConflictException::forSlug('leave')));
        self::assertFalse(WorkflowConflictResponseSubscriber::isConflict(new LogicException('x')));
    }

    #[Test]
    public function otherFailuresAndOtherRoutesAreLeftAlone(): void
    {
        $event = $this->event($this->request(), new LogicException('bug'));
        (new WorkflowConflictResponseSubscriber())->onKernelException($event);
        self::assertInstanceOf(LogicException::class, $event->getThrowable());

        $host = new Request();
        $host->attributes->set('_route', 'app_order');
        $event = $this->event($host, WorkflowConflictException::subjectMissing('leave', [], 1));
        (new WorkflowConflictResponseSubscriber())->onKernelException($event);
        self::assertInstanceOf(WorkflowConflictException::class, $event->getThrowable());
        self::assertNull($event->getResponse());
    }

    private function request(): Request
    {
        $request = Request::create('/workflow/definitions/1/publish', 'POST');
        $request->attributes->set('_route', 'nowo_workflow_definition_publish');

        return $request;
    }

    private function event(Request $request, Throwable $failure): ExceptionEvent
    {
        return new ExceptionEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
    }
}
