<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Controller;

use ArrayObject;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\UiKitBundle\Dialog\RemoteDialog;
use Nowo\WorkflowBundle\Contract\WorkflowUiActionCheckerInterface;
use Nowo\WorkflowBundle\Contract\WorkflowUiTenantResolverInterface;
use Nowo\WorkflowBundle\Controller\WorkflowDefinitionController;
use Nowo\WorkflowBundle\DependencyInjection\Configuration;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Http\WorkflowUiTenancy;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Model\WorkflowUiAccessCheck;
use Nowo\WorkflowBundle\Model\WorkflowUiAction;
use Nowo\WorkflowBundle\Model\WorkflowUiActionCheck;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry;
use Nowo\WorkflowBundle\Service\WorkflowDefinitionBuilder;
use Nowo\WorkflowBundle\Service\WorkflowGraphPresenter;
use Nowo\WorkflowBundle\Tests\Support\ControllerContainerFactory;
use Nowo\WorkflowBundle\Tests\Support\RecordingLogger;
use Nowo\WorkflowBundle\Tests\Support\WorkflowPageFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Translation\IdentityTranslator;
use Throwable;

/**
 * Lazy dialogs (2.3): a place / transition of the flow diagram is its own page. The dialog request (`X-Nowo-Dialog: 1`)
 * gets the fragment, a plain request the full page; both vary on the header and apply exactly the show page's access
 * decision.
 */
final class WorkflowFlowDetailControllerTest extends TestCase
{
    public function testPlaceAnswersTheFragmentToADialogRequest(): void
    {
        $response = $this->controller()->place($this->dialogRequest(), 7, 1);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('place-fragment:review', $response->getContent());
        self::assertContains(RemoteDialog::REQUEST_HEADER, $response->getVary());
    }

    public function testPlaceAnswersTheFullPageWithoutTheHeader(): void
    {
        $response = $this->controller()->place(Request::create('/'), 7, 1);

        self::assertSame('flow-page:place:review:review:@NowoWorkflowBundle/workflow_definition/_flow_place.html.twig', $response->getContent());
        self::assertContains(RemoteDialog::REQUEST_HEADER, $response->getVary(), 'one URL, two answers: caches must vary');
    }

    public function testTransitionAnswersFragmentAndPage(): void
    {
        self::assertSame('transition-fragment:submit', $this->controller()->transition($this->dialogRequest(), 7, 0)->getContent());
        self::assertSame('flow-page:transition:submit:submit:@NowoWorkflowBundle/workflow_definition/_flow_transition.html.twig', $this->controller()->transition(Request::create('/'), 7, 0)->getContent());
    }

    public function testUnknownIndexOrDefinitionAnswers404(): void
    {
        foreach ([fn (): Response => $this->controller()->place($this->dialogRequest(), 7, 9), fn (): Response => $this->controller()->transition(Request::create('/'), 7, 3), fn (): Response => $this->controller()->place(Request::create('/'), 99, 0)] as $call) {
            try {
                $call();
                self::fail('expected a 404');
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * @return iterable<string, array{0: bool, 1: string|null, 2: class-string<Throwable>|null}>
     */
    public static function accessCases(): iterable
    {
        yield 'own tenant, allowed' => [true, 'acme', null];
        yield 'own tenant, checker denies View' => [false, 'acme', AccessDeniedHttpException::class];
        yield 'another tenant\'s definition' => [true, 'other', NotFoundHttpException::class];
        yield 'global definition seen from a tenant (a draft: 404)' => [true, '', NotFoundHttpException::class];
    }

    /**
     * @param class-string<Throwable>|null $expected
     */
    #[DataProvider('accessCases')]
    public function testAccessIsExactlyTheShowPageDecision(bool $allow, ?string $definitionTenant, ?string $expected): void
    {
        /** @var ArrayObject<int, WorkflowUiActionCheck> $checks */
        $checks     = new ArrayObject();
        $controller = $this->controller($this->tenancy($allow, $checks), $definitionTenant);
        $outcomes   = [];
        foreach (['show' => static fn (): Response => $controller->show(Request::create('/'), 7), 'place' => fn (): Response => $controller->place($this->dialogRequest(), 7, 0), 'place page' => static fn (): Response => $controller->place(Request::create('/'), 7, 0), 'transition' => fn (): Response => $controller->transition($this->dialogRequest(), 7, 0)] as $name => $call) {
            try {
                $call();
                $outcomes[$name] = null;
            } catch (AccessDeniedHttpException|NotFoundHttpException $exception) {
                $outcomes[$name] = $exception::class;
            }
        }

        self::assertSame(['show' => $expected, 'place' => $expected, 'place page' => $expected, 'transition' => $expected], $outcomes);
        if ($expected !== NotFoundHttpException::class) {
            self::assertNotCount(0, $checks, 'the host checker was asked');
        }
        foreach ($checks as $check) {
            self::assertSame(WorkflowUiAction::View, $check->action, 'the detail asks for View, as the show page');
        }
    }

    private function dialogRequest(): Request
    {
        $request = Request::create('/');
        $request->headers->set(RemoteDialog::REQUEST_HEADER, '1');

        return $request;
    }

    /**
     * @param ArrayObject<int, WorkflowUiActionCheck> $checks
     */
    private function tenancy(bool $allow, ArrayObject $checks): WorkflowUiTenancy
    {
        $resolver = new class implements WorkflowUiTenantResolverInterface {
            public function tenantFor(WorkflowUiAccessCheck $request): WorkflowTenant
            {
                return new WorkflowTenant('acme');
            }
        };
        $checker = new class($allow, $checks) implements WorkflowUiActionCheckerInterface {
            /**
             * @param ArrayObject<int, WorkflowUiActionCheck> $checks
             */
            public function __construct(private readonly bool $allow, private readonly ArrayObject $checks)
            {
            }

            public function allows(WorkflowUiActionCheck $check): bool
            {
                $this->checks->append($check);

                return $this->allow;
            }
        };

        return new WorkflowUiTenancy(true, $resolver, $checker);
    }

    private function controller(WorkflowUiTenancy $tenancy = new WorkflowUiTenancy(), ?string $tenant = null): WorkflowDefinitionController
    {
        $definition = new WorkflowDefinition('Order', 'order', 'draft', 'App\\Entity\\Order');
        if ($tenant !== null && $tenant !== '') {
            $definition->startAsDraft(new WorkflowTenant($tenant));
        } elseif ($tenant === '') {
            $definition->startAsDraft(null);
        }
        $definition->addPlace(new WorkflowPlace('draft'));
        $definition->addPlace(new WorkflowPlace('review'));
        $definition->addTransition(new WorkflowTransition('submit', ['draft'], ['review']));
        (new ReflectionProperty(WorkflowDefinition::class, 'id'))->setValue($definition, 7);

        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('find')->willReturnCallback(static fn (int $id): ?WorkflowDefinition => $id === 7 ? $definition : null);

        $controller = new WorkflowDefinitionController(
            $repository,
            $this->createMock(EntityManagerInterface::class),
            new DatabaseWorkflowRegistry($this->createMock(WorkflowDefinitionRepository::class), new WorkflowDefinitionBuilder()),
            new WorkflowGraphPresenter(),
            new IdentityTranslator(),
            WorkflowPageFixtures::factory(),
            20,
            null,
            Configuration::DEFAULT_TEMPLATES,
            new RecordingLogger(),
            $tenancy,
        );
        $controller->setContainer(ControllerContainerFactory::create());

        return $controller;
    }
}
