<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowMatchRule;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Enum\WorkflowType;
use Nowo\WorkflowBundle\Form\WorkflowDefinitionFormSection;
use Nowo\WorkflowBundle\Form\WorkflowDefinitionFormType;
use Nowo\WorkflowBundle\Form\WorkflowMatchRuleType;
use Nowo\WorkflowBundle\Form\WorkflowPlaceType;
use Nowo\WorkflowBundle\Form\WorkflowTransitionType;
use Nowo\WorkflowBundle\View\WorkflowPageFactory;
use ReflectionProperty;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function count;

/**
 * Realistic workflow definitions, forms and the page factory for page / contract tests.
 */
final class WorkflowPageFixtures
{
    public static function factory(?string $layout = null): WorkflowPageFactory
    {
        return new WorkflowPageFactory(new StubUrlGenerator(), self::csrf(), $layout);
    }

    public static function csrf(): CsrfTokenManagerInterface
    {
        return new class implements CsrfTokenManagerInterface {
            public function getToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'token-' . $tokenId);
            }

            public function refreshToken(string $tokenId): CsrfToken
            {
                return $this->getToken($tokenId);
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function isTokenValid(CsrfToken $token): bool
            {
                return $token->getValue() === 'token-' . $token->getId();
            }
        };
    }

    /**
     * Purchase order approval: linear state machine with a match rule.
     */
    public static function purchaseOrder(int $id = 1): WorkflowDefinition
    {
        $definition = new WorkflowDefinition('Purchase order approval', 'purchase_order', 'draft', 'App\\Entity\\PurchaseOrder', WorkflowType::StateMachine);
        $definition->setDescription('Approval chain for purchase orders above the department budget.');
        $definition->setPriority(10);
        foreach ([['draft', 'Draft'], ['submitted', 'Submitted'], ['approved', 'Approved']] as $i => [$name, $label]) {
            $definition->addPlace(new WorkflowPlace($name, $label, $i));
        }
        $definition->addTransition(new WorkflowTransition('submit', ['draft'], ['submitted'], 'Submit'));
        $definition->addTransition(new WorkflowTransition('approve', ['submitted'], ['approved'], 'Approve'));
        $definition->addMatchRule(new WorkflowMatchRule('department', 'purchasing'));
        self::setId($definition, $id);

        return $definition;
    }

    /**
     * Document review: branching workflow with a loop (back edge), default matcher, disabled.
     */
    public static function documentReview(int $id = 2): WorkflowDefinition
    {
        $definition = new WorkflowDefinition('Document review', 'document_review', 'draft', 'App\\Entity\\Document', WorkflowType::Workflow);
        $definition->setEnabled(false);
        foreach (['draft', 'legal_review', 'tech_review', 'published'] as $i => $name) {
            $definition->addPlace(new WorkflowPlace($name, null, $i));
        }
        $definition->addTransition(new WorkflowTransition('request_review', ['draft'], ['legal_review', 'tech_review'], 'Request review'));
        $definition->addTransition(new WorkflowTransition('publish', ['legal_review', 'tech_review'], ['published']));
        $definition->addTransition(new WorkflowTransition('reject', ['legal_review'], ['draft'], 'Reject'));
        self::setId($definition, $id);

        return $definition;
    }

    public static function emptyDefinition(int $id = 3): WorkflowDefinition
    {
        $definition = new WorkflowDefinition('Expense claim', 'expense_claim', 'draft', 'App\\Entity\\Expense');
        self::setId($definition, $id);

        return $definition;
    }

    /**
     * @param list<WorkflowDefinition> $items
     *
     * @return array{items: list<WorkflowDefinition>, total: int, page: int, pages: int, page_size: int}
     */
    public static function result(array $items, int $total = 0, int $page = 1, int $pageSize = 20): array
    {
        $total = max($total, count($items));

        return [
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'pages'     => $pageSize > 0 ? max(1, (int) ceil($total / $pageSize)) : 1,
            'page_size' => $pageSize,
        ];
    }

    public static function formFactory(): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addType(FormKitTestSupport::withMerger(new WorkflowDefinitionFormType()))
            ->addType(FormKitTestSupport::withMerger(new WorkflowPlaceType()))
            ->addType(FormKitTestSupport::withMerger(new WorkflowTransitionType()))
            ->addType(FormKitTestSupport::withMerger(new WorkflowMatchRuleType()))
            ->getFormFactory();
    }

    public static function formView(WorkflowDefinition $definition, WorkflowDefinitionFormSection $section, bool $submitInvalid = false): FormView
    {
        $form = self::formFactory()->create(WorkflowDefinitionFormType::class, $definition, ['section' => $section]);
        if ($submitInvalid) {
            $form->submit(['name' => 'Expense claim', 'slug' => 'Not a slug!', 'type' => 'state_machine', 'initialPlace' => 'draft', 'subjectClass' => 'App\\Entity\\Expense', 'markingProperty' => 'status', 'priority' => '0'], false);
            $form->get('slug')->addError(new FormError('Use lowercase letters, digits and underscores only.'));
        }

        return $form->createView();
    }

    public static function setId(object $entity, int $id): void
    {
        (new ReflectionProperty($entity, 'id'))->setValue($entity, $id);
    }
}
