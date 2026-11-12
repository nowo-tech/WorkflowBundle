<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Beacon116;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Service\DatabaseMetadataStore;
use Nowo\WorkflowBundle\Service\WorkflowGraphPresenter;
use Nowo\WorkflowBundle\Service\WorkflowLabelTranslator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Workflow\Transition;

/**
 * 1.16: places and transitions carry a translation key and domain; the plain label (then the name) is the fallback.
 */
final class TranslatableLabelsTest extends TestCase
{
    #[Test]
    public function aKeyIsTranslatedInItsDomainWithTheLabelAsFallback(): void
    {
        $labels = new WorkflowLabelTranslator($this->translator());

        $approved = (new WorkflowPlace('approved', 'Approved'))->setLabelTranslation('workflow.leave.approved', 'workflows');
        self::assertSame('Aprobada', $labels->place($approved));

        $messages = (new WorkflowPlace('rejected', 'Rejected'))->setLabelTranslation('workflow.leave.rejected');
        self::assertSame('Rechazada', $labels->place($messages), 'no domain: messages');

        $missing = (new WorkflowPlace('submitted', 'Submitted'))->setLabelTranslation('workflow.leave.unknown', 'workflows');
        self::assertSame('Submitted', $labels->place($missing), 'no translation: the plain label');

        $nameOnly = (new WorkflowTransition('submit', ['a'], ['b']))->setLabelTranslation('workflow.leave.unknown');
        self::assertSame('submit', $labels->transition($nameOnly), 'no translation, no label: the name');

        $transition = (new WorkflowTransition('approve', ['a'], ['b'], 'Approve'))->setLabelTranslation('workflow.leave.approve', 'workflows');
        self::assertSame('Aprobar', $labels->transition($transition));

        self::assertSame('Approved', (new WorkflowLabelTranslator())->place($approved), 'no translator: the plain label');
        self::assertSame('Approved', $labels->place(new WorkflowPlace('approved', 'Approved')), 'no key: the plain label');
        self::assertSame('fallback', $labels->translate('workflow.leave.empty', 'workflows', 'fallback'), 'an empty translation is no translation');
    }

    #[Test]
    public function keysAndDomainsAreTrimmedAndBlankMeansNone(): void
    {
        $place = (new WorkflowPlace('a'))->setLabelTranslation('  key  ', '  domain ');
        self::assertSame('key', $place->getLabelKey());
        self::assertSame('domain', $place->getLabelDomain());

        $place->setLabelKey('   ')->setLabelDomain('');
        self::assertNull($place->getLabelKey());
        self::assertNull($place->getLabelDomain());
        $place->setLabelKey(null)->setLabelDomain(null);
        self::assertNull($place->getLabelKey());
    }

    #[Test]
    public function theMetadataStoreExposesTheKeyAndDomainNextToThePlainLabel(): void
    {
        $definition = $this->definition();
        $store      = new DatabaseMetadataStore($definition);

        self::assertSame(['label' => 'Approved', 'label_key' => 'workflow.leave.approved', 'label_domain' => 'workflows'], $store->getPlaceMetadata('approved'));
        self::assertSame(['label' => 'requested'], $store->getPlaceMetadata('requested'));
        self::assertSame(['label' => 'Approve', 'label_key' => 'workflow.leave.approve', 'label_domain' => 'messages'], $store->getTransitionMetadata(new Transition('approve', 'requested', 'approved')));
        self::assertSame('workflow.leave.approve', $store->getMetadata(DatabaseMetadataStore::LABEL_KEY, new Transition('approve', 'requested', 'approved')));
        self::assertSame(['label' => 'submit'], $store->getTransitionMetadata(new Transition('submit', 'requested', 'approved')));
    }

    #[Test]
    public function theDiagramShowsTranslatedLabelsAndCopiesKeepThem(): void
    {
        $definition = $this->definition();
        $graph      = (new WorkflowGraphPresenter(new WorkflowLabelTranslator($this->translator())))->present($definition);

        $labels = [];
        foreach ($graph['places'] as $place) {
            $labels[$place['name']] = $place['displayLabel'];
        }
        self::assertSame(['requested' => 'requested', 'approved' => 'Aprobada'], $labels);
        $transitions = [];
        foreach ($graph['transitions'] as $transition) {
            $transitions[$transition['name']] = $transition['displayLabel'];
        }
        self::assertSame('Aprobar', $transitions['approve'] ?? null);

        $copy     = $definition->copyAsDraft(null, 2);
        $approved = $copy->getPlaces()->last();
        self::assertInstanceOf(WorkflowPlace::class, $approved);
        self::assertSame('workflow.leave.approved', $approved->getLabelKey());
        self::assertSame('workflows', $approved->getLabelDomain());
        $approve = $copy->getTransitions()->filter(static fn (WorkflowTransition $t): bool => $t->getName() === 'approve')->first();
        self::assertInstanceOf(WorkflowTransition::class, $approve);
        self::assertSame('workflow.leave.approve', $approve->getLabelKey());
        self::assertNull($approve->getLabelDomain());
    }

    private function definition(): WorkflowDefinition
    {
        $definition = new WorkflowDefinition('Leave', 'leave', 'requested', 'X');
        $definition->addPlace(new WorkflowPlace('requested', null, 0));
        $definition->addPlace((new WorkflowPlace('approved', 'Approved', 1))->setLabelTranslation('workflow.leave.approved', 'workflows'));
        $definition->addTransition((new WorkflowTransition('approve', ['requested'], ['approved'], 'Approve'))->setLabelTranslation('workflow.leave.approve'));
        $definition->addTransition(new WorkflowTransition('submit', ['requested'], ['approved']));

        return $definition;
    }

    private function translator(): Translator
    {
        $translator = new Translator('es');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['workflow.leave.approved' => 'Aprobada', 'workflow.leave.approve' => 'Aprobar', 'workflow.leave.empty' => ''], 'es', 'workflows');
        $translator->addResource('array', ['workflow.leave.rejected' => 'Rechazada', 'workflow.leave.approve' => 'Aprobar'], 'es', 'messages');

        return $translator;
    }
}
