<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Controller;

use Nowo\WorkflowBundle\Controller\DashboardController;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Tests\Support\ControllerContainerFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class DashboardControllerTest extends TestCase
{
    public function testIndexRendersDashboardWithDefinitions(): void
    {
        $definition = new WorkflowDefinition('Order', 'order', 'draft', 'App\\Entity\\Order');
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->expects(self::once())
            ->method('findBy')
            ->with([], ['name' => 'ASC'])
            ->willReturn([$definition]);

        $controller = new DashboardController($repository);
        $controller->setContainer(ControllerContainerFactory::create());

        $response = $controller->index();

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('dashboard', $response->getContent());
    }
}
