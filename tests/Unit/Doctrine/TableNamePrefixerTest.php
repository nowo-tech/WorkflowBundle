<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\WorkflowBundle\Doctrine\TableNamePrefixer;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use PHPUnit\Framework\TestCase;

final class TableNamePrefixerTest extends TestCase
{
    public function testApplyPrefixReturnsIdentifierWhenUsingDefaultPrefix(): void
    {
        $prefixer = new TableNamePrefixer(TableNamePrefixer::DEFAULT_PREFIX);

        self::assertSame('workflow_definition', $prefixer->applyPrefix('workflow_definition'));
        self::assertSame(TableNamePrefixer::DEFAULT_PREFIX, $prefixer->getTablePrefix());
    }

    public function testApplyPrefixReplacesDefaultPrefix(): void
    {
        $prefixer = new TableNamePrefixer('acme_');

        self::assertSame('acme_definition', $prefixer->applyPrefix('workflow_definition'));
        self::assertSame('acme_place', $prefixer->applyPrefix('workflow_place'));
        self::assertSame('uniq_acme_definition_slug', $prefixer->applyPrefix('uniq_workflow_definition_slug'));
        self::assertSame('other_name', $prefixer->applyPrefix('other_name'));
    }

    public function testApplyToClassMetadataRenamesTableAndConstraints(): void
    {
        $prefixer = new TableNamePrefixer('acme_');
        /** @var ClassMetadata<object> $metadata */
        $metadata = new ClassMetadata(WorkflowDefinition::class);
        $metadata->setPrimaryTable([
            'name'              => 'workflow_definition',
            'uniqueConstraints' => [
                'uniq_workflow_definition_slug' => ['columns' => ['slug']],
            ],
        ]);

        $prefixer->applyToClassMetadata($metadata);

        self::assertSame('acme_definition', $metadata->getTableName());
        self::assertIsArray($metadata->table['uniqueConstraints'] ?? null);
        self::assertArrayHasKey('uniq_acme_definition_slug', $metadata->table['uniqueConstraints']);
    }

    public function testApplyToClassMetadataIsNoOpForDefaultPrefix(): void
    {
        $prefixer = new TableNamePrefixer(TableNamePrefixer::DEFAULT_PREFIX);
        /** @var ClassMetadata<object> $metadata */
        $metadata = new ClassMetadata(WorkflowDefinition::class);
        $metadata->setPrimaryTable(['name' => 'workflow_definition']);

        $prefixer->applyToClassMetadata($metadata);

        self::assertSame('workflow_definition', $metadata->getTableName());
    }
}
