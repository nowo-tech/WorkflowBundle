<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Collation;

use Nowo\MigrationsKitContracts\Collation\ExactKeyColumn;
use Nowo\WorkflowBundle\Collation\WorkflowExactKeyColumns;
use Nowo\WorkflowBundle\Doctrine\TableNamePrefixer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_map;

#[CoversClass(WorkflowExactKeyColumns::class)]
final class WorkflowExactKeyColumnsTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function describe(string $prefix): array
    {
        return array_map(
            static fn (ExactKeyColumn $c): string => $c->describe(),
            (new WorkflowExactKeyColumns(new TableNamePrefixer($prefix)))->exactKeyColumns(),
        );
    }

    public function testDeclaresTheKeyColumnsOfBothTables(): void
    {
        self::assertSame([
            'workflow_definition.tenant_key',
            'workflow_definition.slug',
            'workflow_subject_pin.tenant_key',
            'workflow_subject_pin.workflow_slug',
            'workflow_subject_pin.subject_ref',
        ], $this->describe(TableNamePrefixer::DEFAULT_PREFIX));
    }

    public function testTheConfiguredTablePrefixIsApplied(): void
    {
        // A host-renamed table must be named as the mapping names it, or the host-side aggregate run reports a false
        // failure on exactly the hosts that use a prefix.
        self::assertSame([
            'acme_definition.tenant_key',
            'acme_definition.slug',
            'acme_subject_pin.tenant_key',
            'acme_subject_pin.workflow_slug',
            'acme_subject_pin.subject_ref',
        ], $this->describe('acme_'));
    }

    public function testEveryDeclaredColumnCarriesItsOwnerAndPurpose(): void
    {
        foreach ((new WorkflowExactKeyColumns(new TableNamePrefixer()))->exactKeyColumns() as $column) {
            self::assertSame(WorkflowExactKeyColumns::OWNER, $column->owner);
            self::assertNotSame('', $column->purpose);
            self::assertTrue($column->active);
        }
    }
}
