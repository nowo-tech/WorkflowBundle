<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Controller;

use Nowo\WorkflowBundle\Controller\DashboardController;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Tests\Support\ControllerContainerFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DashboardControllerTest extends TestCase
{
    #[Test]
    public function testIndexRendersDashboardWithDefinitions(): void
    {
        $definition = new WorkflowDefinition('Order', 'order', 'draft', 'App\\Entity\\Order');
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->expects(self::once())
            ->method('paginateByName')
            ->with(1, 20)
            ->willReturn([
                'items'     => [$definition],
                'total'     => 1,
                'page'      => 1,
                'pages'     => 1,
                'page_size' => 20,
            ]);

        $controller = new DashboardController($repository, 20);
        $controller->setContainer(ControllerContainerFactory::create());

        $response = $controller->index(Request::create('/'));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('dashboard', $response->getContent());
    }
}
