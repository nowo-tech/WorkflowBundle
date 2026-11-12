<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Tenancy;

use Nowo\WorkflowBundle\Audit\AfterCommitDispatcher;
use Nowo\WorkflowBundle\Contract\WorkflowSubjectVariablesProviderInterface;
use Nowo\WorkflowBundle\Contract\WorkflowTenantResolverInterface;
use Nowo\WorkflowBundle\Contract\WorkflowUiActionCheckerInterface;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\EventListener\TenantIsolationGuardSubscriber;
use Nowo\WorkflowBundle\EventListener\TransitionAuditSubscriber;
use Nowo\WorkflowBundle\EventListener\TransitionGuardSubscriber;
use Nowo\WorkflowBundle\EventListener\WorkflowUiRouteContextSubscriber;
use Nowo\WorkflowBundle\Http\WorkflowUiTenancy;
use Nowo\WorkflowBundle\Model\WorkflowContext;
use Nowo\WorkflowBundle\Model\WorkflowSubject;
use Nowo\WorkflowBundle\Model\WorkflowSubjectVariables;
use Nowo\WorkflowBundle\Model\WorkflowUiAction;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\DatabaseMetadataStore;
use Nowo\WorkflowBundle\Service\WorkflowResolver;
use Nowo\WorkflowBundle\Tenancy\SecurityTokenActorProvider;
use Nowo\WorkflowBundle\Tenancy\SubjectPinStore;
use Nowo\WorkflowBundle\Tenancy\TenancyContext;
use Nowo\WorkflowBundle\Tenancy\WorkflowDefinitionLocator;
use Nowo\WorkflowBundle\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplObjectStorage;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RequestContextAwareInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Metadata\InMemoryMetadataStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;

use function str_repeat;

/**
 * Edge cases of the 1.15 listeners and helpers (the kernel tests cover the main paths).
 */
final class TenancyListenersTest extends TestCase
{
    #[Test]
    public function listenersIgnoreWorkflowsNotBuiltByTheBundle(): void
    {
        [$workflow, $transition] = $this->workflow([]);
        $guard                   = new GuardEvent(new stdClass(), new Marking(['a' => 1]), $transition, $workflow);

        (new TransitionGuardSubscriber(new TenancyContext()))->onGuard($guard);
        (new TenantIsolationGuardSubscriber($this->nothingLocator()))->onGuard($guard);
        self::assertTrue($guard->getTransitionBlockerList()->isEmpty());

        $dispatcher = new AfterCommitDispatcher();
        (new TransitionAuditSubscriber($dispatcher, new TenancyContext()))->onCompleted(new CompletedEvent(new stdClass(), new Marking(['b' => 1]), $transition, $workflow));
        [$bundleWorkflow] = $this->workflow([DatabaseMetadataStore::DEFINITION_ID => 1]);
        (new TransitionAuditSubscriber($dispatcher, new TenancyContext()))->onCompleted(new CompletedEvent(new stdClass(), new Marking(['b' => 1]), null, $bundleWorkflow));
        self::assertSame(0, $dispatcher->countPending());
    }

    #[Test]
    public function guardsWithoutTheirPortOrWithABrokenConditionBlock(): void
    {
        $guards                  = [DatabaseMetadataStore::GUARD_PERMISSION => 'leave.approve', DatabaseMetadataStore::GUARD_EXPRESSION => 'days <= 2'];
        [$workflow, $transition] = $this->workflow([DatabaseMetadataStore::DEFINITION_ID => 1], $guards);
        $guard                   = new GuardEvent(new stdClass(), new Marking(['a' => 1]), $transition, $workflow);
        (new TransitionGuardSubscriber(new TenancyContext()))->onGuard($guard);
        self::assertCount(2, $guard->getTransitionBlockerList());

        $provider = $this->createMock(WorkflowSubjectVariablesProviderInterface::class);
        $provider->method('variablesOf')->willReturnCallback(static fn (WorkflowSubject $subject): WorkflowSubjectVariables => new WorkflowSubjectVariables(['type' => 'x']));
        $logger = new RecordingLogger();
        $guard  = new GuardEvent(new stdClass(), new Marking(['a' => 1]), $transition, $workflow);
        (new TransitionGuardSubscriber(new TenancyContext(), null, $provider, $logger))->onGuard($guard);
        self::assertCount(2, $guard->getTransitionBlockerList(), 'missing variable: blocked');
        self::assertStringContainsString('could not be evaluated', $logger->records[0]['message']);
    }

    #[Test]
    public function theIsolationGuardBlocksAVersionThatIsNotFound(): void
    {
        [$workflow, $transition] = $this->workflow([DatabaseMetadataStore::DEFINITION_ID => 5]);
        $guard                   = new GuardEvent(new stdClass(), new Marking(['a' => 1]), $transition, $workflow);
        (new TenantIsolationGuardSubscriber($this->nothingLocator()))->onGuard($guard);
        $codes = [];
        foreach ($guard->getTransitionBlockerList() as $blocker) {
            $codes[] = $blocker->getCode();
        }
        self::assertSame([TenantIsolationGuardSubscriber::CODE], $codes);
    }

    #[Test]
    public function theRouteContextSubscriberOnlyCopiesScalarPrefixVariables(): void
    {
        $context = new RequestContext();
        $router  = $this->createMock(RequestContextAwareInterface::class);
        $router->method('getContext')->willReturn($context);
        $kernel     = $this->createMock(HttpKernelInterface::class);
        $subscriber = new WorkflowUiRouteContextSubscriber($router);

        $request = new Request();
        $request->attributes->set('_route', 'nowo_workflow_definition_index');
        $request->attributes->set('_route_params', ['uuid' => 'org-a', 'id' => '3', '_locale' => 'en', 'page' => 2, 'list' => ['x'], 5 => 'n']);
        $subscriber->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertSame(['uuid' => 'org-a', 'page' => '2'], $context->getParameters());

        $context->setParameter('page', 'changed by someone else');
        $subscriber->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST));
        self::assertCount(2, $context->getParameters(), 'sub-request: untouched');
        $subscriber->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertSame(['page' => 'changed by someone else'], $context->getParameters(), 'only the values it set');

        $other = new Request();
        $other->attributes->set('_route', 'app_home');
        $other->attributes->set('_route_params', ['uuid' => 'org-b']);
        $subscriber->onKernelRequest(new RequestEvent($kernel, $other, HttpKernelInterface::MAIN_REQUEST));
        self::assertArrayNotHasKey('uuid', $context->getParameters(), 'not a Workflow UI route');

        $invalid = new Request();
        $invalid->attributes->set('_route', 'nowo_workflow_dashboard');
        $invalid->attributes->set('_route_params', 'not an array');
        self::assertSame([], WorkflowUiRouteContextSubscriber::prefixVariables($invalid));

        $none = new WorkflowUiRouteContextSubscriber();
        $none->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $none->onFinishRequest(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        self::assertArrayHasKey('kernel.request', WorkflowUiRouteContextSubscriber::getSubscribedEvents());
    }

    #[Test]
    public function theUiTenancyHelperFailsClosed(): void
    {
        $request = new Request();
        $plain   = new WorkflowUiTenancy();
        self::assertNull($plain->tenant($request));
        $plain->denyUnlessAllowed($request, WorkflowUiAction::Edit, null);

        $provider = $this->createMock(WorkflowSubjectVariablesProviderInterface::class);
        $fields   = (new WorkflowUiTenancy(variablesProvider: $provider))->guardFields(new WorkflowDefinition('X', 'x', 'a', 'App\\Missing'));
        self::assertSame(['permission' => false, 'variables' => [], 'automatic' => true], $fields);

        $this->expectException(AccessDeniedHttpException::class);
        (new WorkflowUiTenancy(true))->denyUnlessAllowed($request, WorkflowUiAction::View, null);
    }

    #[Test]
    public function theUiActionCheckerIsAskedWithoutTenancyToo(): void
    {
        $checker = $this->createMock(WorkflowUiActionCheckerInterface::class);
        $checker->method('allows')->willReturn(false);

        $this->expectException(AccessDeniedHttpException::class);
        (new WorkflowUiTenancy(false, null, $checker))->denyUnlessAllowed(new Request(), WorkflowUiAction::View, null);
    }

    #[Test]
    public function theDefaultActorIsTheTokensUser(): void
    {
        $tokens = new TokenStorage();
        self::assertNull((new SecurityTokenActorProvider())->currentActor());
        self::assertNull((new SecurityTokenActorProvider($tokens))->currentActor());
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('bob', null), 'main'));
        self::assertSame('bob', (new SecurityTokenActorProvider($tokens))->currentActor()?->id);
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser(str_repeat('x', 300), null), 'main'));
        self::assertNull((new SecurityTokenActorProvider($tokens))->currentActor(), 'not an actor reference');
    }

    #[Test]
    public function withoutTenancyTheLocatorIsThe114Lookup(): void
    {
        $definition = new WorkflowDefinition('X', 'x', 'a', stdClass::class);
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findOneBySlug')->willReturn($definition);
        $resolver = new WorkflowResolver($repository);
        $repository->method('findEnabledCandidates')->willReturn([$definition]);
        $locator = new WorkflowDefinitionLocator($repository, $resolver, new TenancyContext(), new SubjectPinStore($repository));

        self::assertFalse($locator->tenancyEnabled());
        self::assertSame($definition, $locator->forSubject(new stdClass(), 'x'));
        self::assertSame($definition, $locator->resolveForSubject(new stdClass()));
        self::assertSame($definition, $locator->resolveForContext(new stdClass(), new WorkflowContext(subjectClass: stdClass::class)));
        $locator->pin(new stdClass(), $definition);
    }

    /**
     * A tenancy locator over an empty repository: every lookup is "not found".
     */
    private function nothingLocator(): WorkflowDefinitionLocator
    {
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $tenants    = $this->createMock(WorkflowTenantResolverInterface::class);

        return new WorkflowDefinitionLocator($repository, new WorkflowResolver($repository), new TenancyContext($tenants), new SubjectPinStore($repository));
    }

    /**
     * @param array<string, mixed> $workflowMetadata
     * @param array<string, mixed> $transitionMetadata
     *
     * @return array{StateMachine, Transition}
     */
    private function workflow(array $workflowMetadata, array $transitionMetadata = []): array
    {
        $transition = new Transition('go', 'a', 'b');
        /** @var SplObjectStorage<Transition, array> $metadata */ // @phpstan-ignore missingType.iterableValue
        $metadata = new SplObjectStorage();
        $metadata->offsetSet($transition, $transitionMetadata);

        return [new StateMachine(new Definition(['a', 'b'], [$transition], 'a', new InMemoryMetadataStore($workflowMetadata, [], $metadata)), null, null, 'x'), $transition];
    }
}
