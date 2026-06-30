<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\WorkflowBundle\Controller\WorkflowDefinitionController;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry;
use Nowo\WorkflowBundle\Service\WorkflowDefinitionBuilder;
use Nowo\WorkflowBundle\Service\WorkflowGraphPresenter;
use Nowo\WorkflowBundle\Tests\Support\ControllerContainerFactory;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Translation\IdentityTranslator;

final class WorkflowDefinitionControllerTest extends TestCase
{
    public function testIndexRendersDefinitionsList(): void
    {
        $controller = $this->createController(definitions: []);

        $response = $controller->index();

        self::assertSame('index', $response->getContent());
    }

    public function testEditRedirectsToGeneralSection(): void
    {
        $controller = $this->createController();

        $response = $controller->edit(5);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertStringContainsString('nowo_workflow_definition_edit_general', (string) $response->headers->get('Location'));
    }

    public function testShowRendersDefinitionGraph(): void
    {
        $definition = $this->definitionWithId(7);
        $controller = $this->createController(definitionById: $definition);

        $response = $controller->show(7);

        self::assertSame('show', $response->getContent());
    }

    public function testShowBySlugUsesSlugLookup(): void
    {
        $definition = $this->definitionWithId(3);
        $controller = $this->createController(definitionBySlug: $definition);

        $response = $controller->showBySlug('order');

        self::assertSame('show', $response->getContent());
    }

    public function testShowThrowsWhenDefinitionMissing(): void
    {
        $controller = $this->createController();

        $this->expectException(NotFoundHttpException::class);
        $controller->show(99);
    }

    public function testDeleteThrowsWhenCsrfTokenInvalid(): void
    {
        $definition = $this->definitionWithId(4);
        $controller = $this->createController(definitionById: $definition);

        $request = Request::create('/definitions/4/delete', 'POST', ['_token' => 'invalid']);

        $this->expectException(AccessDeniedException::class);
        $controller->delete($request, 4);
    }

    public function testDeleteRemovesDefinitionWhenCsrfValid(): void
    {
        $definition = $this->definitionWithId(4);
        $em         = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('remove')->with($definition);
        $em->expects(self::once())->method('flush');

        $registry = $this->createMock(WorkflowDefinitionRepository::class);
        $registry->method('findOneBySlug')->willReturn(null);
        $realRegistry = new DatabaseWorkflowRegistry($registry, new WorkflowDefinitionBuilder());

        $controller = $this->createController(
            definitionById: $definition,
            entityManager: $em,
            registry: $realRegistry,
        );

        $request  = Request::create('/definitions/4/delete', 'POST', ['_token' => 'valid']);
        $response = $controller->delete($request, 4);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
    }

    public function testShowBySlugThrowsWhenMissing(): void
    {
        $controller = $this->createController();

        $this->expectException(NotFoundHttpException::class);
        $controller->showBySlug('missing');
    }

    public function testEditGeneralGetRendersForm(): void
    {
        $definition = $this->definitionWithId(2);
        $controller = $this->createController(definitionById: $definition);
        $request    = Request::create('/definitions/2/edit/general', 'GET');

        $response = $controller->editGeneral($request, 2);

        self::assertSame('form', $response->getContent());
    }

    public function testEditGeneralPostUpdatesDefinition(): void
    {
        $definition = $this->definitionWithId(2);
        $em         = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $controller = $this->createController(definitionById: $definition, entityManager: $em);
        $request    = Request::create('/definitions/2/edit/general', 'POST', [
            'workflow_definition_form' => $this->generalFormData('order', 'Updated order'),
        ]);

        $response = $controller->editGeneral($request, 2);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('Updated order', $definition->getName());
    }

    public function testNewRendersFormOnGet(): void
    {
        $controller = $this->createController();
        $request    = Request::create('/definitions/new', 'GET');

        $response = $controller->new($request);

        self::assertSame('form', $response->getContent());
    }

    public function testNewPostPersistsDefinition(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->willReturnCallback(static function (WorkflowDefinition $definition): void {
            $reflection = new ReflectionProperty(WorkflowDefinition::class, 'id');
            $reflection->setAccessible(true);
            $reflection->setValue($definition, 42);
        });
        $em->expects(self::once())->method('flush');

        $controller = $this->createController(entityManager: $em);
        $request    = Request::create('/definitions/new', 'POST', [
            'workflow_definition_form' => $this->generalFormData('new_workflow', 'New workflow'),
        ]);

        $response = $controller->new($request);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertStringContainsString('nowo_workflow_definition_edit_match_rules', (string) $response->headers->get('Location'));
    }

    public function testEditMatchRulesPostUpdatesRules(): void
    {
        $definition = $this->definitionWithId(2);
        $em         = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $controller = $this->createController(definitionById: $definition, entityManager: $em);
        $request    = Request::create('/definitions/2/edit/match-rules', 'POST', [
            'workflow_definition_form' => [
                'matchRules' => [
                    [
                        'parameterKey'   => 'tenant',
                        'parameterValue' => 'acme',
                        'sortOrder'      => 0,
                    ],
                ],
            ],
        ]);

        $response = $controller->editMatchRules($request, 2);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertCount(1, $definition->getMatchRules());
    }

    public function testEditPlacesPostUpdatesPlaces(): void
    {
        $definition = $this->definitionWithId(2);
        $em         = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $controller = $this->createController(definitionById: $definition, entityManager: $em);
        $request    = Request::create('/definitions/2/edit/places', 'POST', [
            'workflow_definition_form' => [
                'places' => [
                    [
                        'name'      => 'review',
                        'label'     => 'Review',
                        'sortOrder' => 1,
                    ],
                ],
            ],
        ]);

        $response = $controller->editPlaces($request, 2);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertCount(1, $definition->getPlaces());
        $place = $definition->getPlaces()->first();
        self::assertInstanceOf(WorkflowPlace::class, $place);
        self::assertSame('review', $place->getName());
    }

    public function testEditTransitionsPostUpdatesTransitions(): void
    {
        $definition = $this->definitionWithId(2);
        $definition->addPlace(new WorkflowPlace('approved'));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $controller = $this->createController(definitionById: $definition, entityManager: $em);
        $request    = Request::create('/definitions/2/edit/transitions', 'POST', [
            'workflow_definition_form' => [
                'transitions' => [
                    [
                        'name'       => 'approve',
                        'label'      => 'Approve',
                        'fromPlaces' => ['draft'],
                        'toPlaces'   => ['approved'],
                    ],
                ],
            ],
        ]);

        $response = $controller->editTransitions($request, 2);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertCount(1, $definition->getTransitions());
    }

    /**
     * @return array<string, mixed>
     */
    private function generalFormData(string $slug, string $name): array
    {
        return [
            'name'            => $name,
            'slug'            => $slug,
            'type'            => 'state_machine',
            'initialPlace'    => 'draft',
            'subjectClass'    => 'App\\Entity\\Subject',
            'markingProperty' => 'status',
            'enabled'         => true,
            'priority'        => 0,
            'description'     => '',
        ];
    }

    /**
     * @param list<WorkflowDefinition> $definitions
     */
    private function createController(
        array $definitions = [],
        ?WorkflowDefinition $definitionById = null,
        ?WorkflowDefinition $definitionBySlug = null,
        ?EntityManagerInterface $entityManager = null,
        ?DatabaseWorkflowRegistry $registry = null,
    ): WorkflowDefinitionController {
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('findBy')->willReturn($definitions);
        $repository->method('find')->willReturnCallback(
            static fn (int $id): ?WorkflowDefinition => $definitionById instanceof WorkflowDefinition && $definitionById->getId() === $id
                ? $definitionById
                : null,
        );
        $repository->method('findOneBySlug')->willReturnCallback(
            static fn (string $slug): ?WorkflowDefinition => $definitionBySlug instanceof WorkflowDefinition && $definitionBySlug->getSlug() === $slug
                ? $definitionBySlug
                : null,
        );

        $graphPresenter = new WorkflowGraphPresenter();

        $controller = new WorkflowDefinitionController(
            $repository,
            $entityManager ?? $this->createMock(EntityManagerInterface::class),
            $registry ?? new DatabaseWorkflowRegistry(
                $this->createMock(WorkflowDefinitionRepository::class),
                new WorkflowDefinitionBuilder(),
            ),
            $graphPresenter,
            new IdentityTranslator(),
        );
        $controller->setContainer(ControllerContainerFactory::create());

        return $controller;
    }

    private function definitionWithId(int $id): WorkflowDefinition
    {
        $definition = new WorkflowDefinition('Order', 'order', 'draft', 'App\\Entity\\Order');
        $definition->addPlace(new WorkflowPlace('draft'));
        $reflection = new ReflectionProperty(WorkflowDefinition::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($definition, $id);

        return $definition;
    }
}
