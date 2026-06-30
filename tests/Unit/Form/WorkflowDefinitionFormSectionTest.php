<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Form;

use Nowo\WorkflowBundle\Form\WorkflowDefinitionFormSection;
use PHPUnit\Framework\TestCase;

final class WorkflowDefinitionFormSectionTest extends TestCase
{
    public function testRouteNamesAndTitleKeys(): void
    {
        self::assertSame('nowo_workflow_definition_edit_general', WorkflowDefinitionFormSection::General->routeName());
        self::assertSame('page.edit_definition_general', WorkflowDefinitionFormSection::General->titleKey());
        self::assertSame('nowo_workflow_definition_edit_transitions', WorkflowDefinitionFormSection::Transitions->routeName());
        self::assertSame('page.edit_definition_transitions', WorkflowDefinitionFormSection::Transitions->titleKey());
    }
}
