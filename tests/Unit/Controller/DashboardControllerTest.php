<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Controller;

use Nowo\WorkflowBundle\Controller\DashboardController;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Tests\Support\ControllerContainerFactory;
use Nowo\WorkflowBundle\Tests\Support\WorkflowPageFixtures;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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

        $repository->expects(self::once())->method('countEnabled')->willReturn(1);

        $controller = new DashboardController($repository, WorkflowPageFixtures::factory(), 20);
        $controller->setContainer(ControllerContainerFactory::create());

        $response = $controller->index();

        self::assertSame('dashboard:3:1/1', $response->getContent());
    }
}
