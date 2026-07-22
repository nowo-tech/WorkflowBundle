<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\WorkflowBundle\Doctrine\TableNamePrefixer;
use Nowo\WorkflowBundle\Doctrine\TablePrefixSubscriber;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TablePrefixSubscriberTest extends TestCase
{
    public function testLoadClassMetadataAppliesPrefixToBundleEntities(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        /** @var ClassMetadata<object> $metadata */
        $metadata = new ClassMetadata(WorkflowDefinition::class);
        $metadata->setPrimaryTable(['name' => 'workflow_definition']);

        $subscriber = new TablePrefixSubscriber(new TableNamePrefixer('acme_'));
        $subscriber->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $entityManager));

        self::assertSame('acme_definition', $metadata->getTableName());
    }

    public function testLoadClassMetadataIgnoresForeignEntities(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        /** @var ClassMetadata<object> $metadata */
        $metadata = new ClassMetadata(stdClass::class);
        $metadata->setPrimaryTable(['name' => 'workflow_definition']);

        $subscriber = new TablePrefixSubscriber(new TableNamePrefixer('acme_'));
        $subscriber->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $entityManager));

        self::assertSame('workflow_definition', $metadata->getTableName());
    }

    public function testGetSubscribedEvents(): void
    {
        self::assertSame(['loadClassMetadata'], (new TablePrefixSubscriber(new TableNamePrefixer()))->getSubscribedEvents());
    }
}
