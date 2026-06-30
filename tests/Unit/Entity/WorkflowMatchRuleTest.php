<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Entity;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowMatchRule;
use PHPUnit\Framework\TestCase;

final class WorkflowMatchRuleTest extends TestCase
{
    public function testMetadataAndWorkflowLink(): void
    {
        $rule = new WorkflowMatchRule('tenant', 'acme', 5);
        $rule->setMetadata(['source' => 'demo'])->setParameterKey('t')->setParameterValue('globex');

        self::assertSame(['source' => 'demo'], $rule->getMetadata());
        self::assertSame('t', $rule->getParameterKey());
        self::assertSame('globex', $rule->getParameterValue());
        self::assertSame(5, $rule->getSortOrder());
        self::assertNull($rule->getId());

        $definition = new WorkflowDefinition('W', 'w', 'draft', 'App\\Entity\\X');
        $rule->setWorkflow($definition);
        self::assertSame($definition, $rule->getWorkflow());

        $rule->setSortOrder(10);
        self::assertSame(10, $rule->getSortOrder());
    }
}
