<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Collation;

use Nowo\MigrationsKitBundle\Collation\ExactKeyColumn;
use Nowo\MigrationsKitBundle\Collation\ExactKeyColumnsInterface;
use Nowo\WorkflowBundle\Doctrine\BinaryKeyCollationSubscriber;
use Nowo\WorkflowBundle\Doctrine\TableNamePrefixer;

/**
 * Declares the key columns of the bundle tables as identity keys (REQ-SEC-006).
 *
 * These are the columns {@see BinaryKeyCollationSubscriber} gives a binary NO PAD
 * collation when the schema is generated. The declaration is what makes that verifiable against a real database:
 * nowo:migrations:check-key-collations fails when one of them is not binary NO PAD, including the case the listener
 * cannot cover — a table created before 1.15.2, converted by hand, or a column added later and never collated.
 *
 * Table names come from {@see TableNamePrefixer}, never from a literal: a host that sets nowo_workflow.table_prefix
 * has `acme_definition`, not `workflow_definition`, and a literal would report a false failure on exactly those
 * hosts. The declaration follows the same prefix the mapping does.
 *
 * Registered only when MigrationsKitBundle is installed (optional dependency); it adds nothing at runtime otherwise.
 */
final readonly class WorkflowExactKeyColumns implements ExactKeyColumnsInterface
{
    public const OWNER = 'nowo-tech/workflow-bundle';

    /** @var array<string, array<string, string>> unprefixed table => column => what the key is */
    private const COLUMNS = [
        'workflow_definition' => [
            'tenant_key' => 'tenant key: selects the definitions of one tenant, part of a unique index',
            'slug'       => 'definition slug: the key a host looks a workflow up by',
        ],
        'workflow_subject_pin' => [
            'tenant_key'    => 'tenant key of the pinned subject',
            'workflow_slug' => 'definition slug the subject is pinned to',
            'subject_ref'   => 'host subject reference: an id supplied by the host, compared with =',
        ],
    ];

    public function __construct(private TableNamePrefixer $prefixer)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function exactKeyColumns(): array
    {
        $columns = [];
        foreach (self::COLUMNS as $table => $declared) {
            foreach ($declared as $column => $purpose) {
                $columns[] = new ExactKeyColumn($this->prefixer->applyPrefix($table), $column, self::OWNER, $purpose);
            }
        }

        return $columns;
    }
}
