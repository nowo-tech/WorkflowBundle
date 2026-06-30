<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Service;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Service\DatabaseMetadataStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Transition;

final class DatabaseMetadataStoreTest extends TestCase
{
    public function testMetadataForWorkflowPlaceAndTransition(): void
    {
        $definition = new WorkflowDefinition('Order', 'order', 'draft', 'App\\Entity\\Order');
        $definition->addPlace(new WorkflowPlace('draft', 'Draft', 0));
        $definition->addTransition(new WorkflowTransition('approve', ['draft'], ['approved'], 'Approve'));

        $store = new DatabaseMetadataStore($definition);

        self::assertSame('order', $store->getMetadata('slug'));
        self::assertSame('Draft', $store->getMetadata('label', 'draft'));
        self::assertSame('Approve', $store->getMetadata('label', new Transition('approve', ['draft'], ['approved'])));
        self::assertNull($store->getMetadata('missing', 'unknown'));
    }

    public function testGetWorkflowPlaceAndTransitionMetadataArrays(): void
    {
        $definition = new WorkflowDefinition('Order', 'order', 'draft', 'App\\Entity\\Order');
        $definition->addPlace(new WorkflowPlace('draft', null, 0));

        $store = new DatabaseMetadataStore($definition);

        self::assertSame('order', $store->getWorkflowMetadata()['slug']);
        self::assertSame(['label' => 'draft'], $store->getPlaceMetadata('draft'));
        self::assertSame([], $store->getPlaceMetadata('missing'));
        self::assertSame([], $store->getTransitionMetadata(new Transition('x', [], [])));
    }
}
