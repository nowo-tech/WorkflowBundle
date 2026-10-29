<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration;

use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowMatchRule;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use PHPUnit\Framework\TestCase;

use function implode;

/**
 * The bundle schema must be creatable on MySQL (demo database, REQ-DEMO-011) and PostgreSQL: no literal defaults on
 * JSON columns (MySQL error 1101).
 */
final class MySqlSchemaTest extends TestCase
{
    public function testJsonColumnsHaveNoDatabaseDefault(): void
    {
        $em       = IntegrationEntityManagerFactory::createInMemory();
        $metadata = $em->getClassMetadata(WorkflowDefinition::class)->getFieldMapping('metadata');

        self::assertArrayNotHasKey('default', $metadata->options ?? []);
    }

    public function testCreateSqlOnMySqlAndPostgreSqlHasNoJsonDefault(): void
    {
        $em     = IntegrationEntityManagerFactory::createInMemory();
        $schema = (new SchemaTool($em))->getSchemaFromMetadata([
            $em->getClassMetadata(WorkflowDefinition::class),
            $em->getClassMetadata(WorkflowMatchRule::class),
            $em->getClassMetadata(WorkflowPlace::class),
            $em->getClassMetadata(WorkflowTransition::class),
        ]);

        foreach ([new MySQL80Platform(), new PostgreSQLPlatform()] as $platform) {
            $sql = implode("\n", $schema->toSql($platform));

            self::assertStringContainsString('metadata JSON NOT NULL', $sql);
            self::assertStringNotContainsString("DEFAULT '{}'", $sql);
        }
    }
}
