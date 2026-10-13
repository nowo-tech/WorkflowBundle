<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration;

use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Form\WorkflowDefinitionFormSection;
use Nowo\WorkflowBundle\Form\WorkflowDefinitionFormType;
use Nowo\WorkflowBundle\Service\LocaleManager;
use Nowo\WorkflowBundle\Tests\Support\FormKitTestSupport;
use Nowo\WorkflowBundle\Tests\Support\StubUrlGenerator;
use Nowo\WorkflowBundle\Twig\WorkflowExtension;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Bridge\Twig\AppVariable;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\RoutingExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Translation\IdentityTranslator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

use function dirname;

/**
 * Renders the real Workflow UI form template through base.html.twig and the default layout.html.twig.
 *
 * Regression: the default layout rendered {@code nowo_ui_content} twice (inside {@code body} and again
 * directly), so form pages failed with "Field "name" has already been rendered".
 */
final class WorkflowDefinitionFormTemplateTest extends TestCase
{
    public function testNewDefinitionPageRendersGeneralFormOnce(): void
    {
        $html = $this->renderForm(null);

        self::assertSame(1, substr_count($html, 'id="workflow-definition-form"'));
        self::assertSame(1, substr_count($html, 'name="workflow_definition_form[name]"'));
        self::assertSame(1, substr_count($html, 'nowo-ui-page-header'));
    }

    public function testEditGeneralPageRendersGeneralFormOnce(): void
    {
        $definition = new WorkflowDefinition('Order', 'order', 'draft', 'App\\Entity\\Order');
        $definition->addPlace(new WorkflowPlace('draft'));
        (new ReflectionProperty(WorkflowDefinition::class, 'id'))->setValue($definition, 2);

        $html = $this->renderForm($definition);

        self::assertSame(1, substr_count($html, 'id="workflow-definition-form"'));
        self::assertSame(1, substr_count($html, 'name="workflow_definition_form[name]"'));
        self::assertSame(1, substr_count($html, 'nowo-ui-page-header'));
        self::assertStringContainsString('<code>order</code>', $html);
    }

    private function renderForm(?WorkflowDefinition $definition): string
    {
        $request = Request::create('/workflow/definitions/new');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $root   = dirname(__DIR__, 2);
        $loader = new FilesystemLoader([$root . '/vendor/symfony/twig-bridge/Resources/views/Form']);
        $loader->addPath($root . '/src/Resources/views', 'NowoWorkflowBundle');

        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension(new IdentityTranslator()));
        $twig->addExtension(new RoutingExtension(new StubUrlGenerator()));
        $twig->addExtension(new FormExtension());
        $twig->addExtension(new WorkflowExtension(
            new LocaleManager($requestStack, ['en', 'es'], 'en'),
            '@NowoWorkflowBundle/layout.html.twig',
            'bootstrap5',
            'bootstrap-icons',
        ));
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => static fn (): FormRenderer => new FormRenderer(
                new TwigRendererEngine(['form_div_layout.html.twig'], $twig),
            ),
        ]));

        $app = new AppVariable();
        $app->setRequestStack($requestStack);
        $twig->addGlobal('app', $app);

        $form = $this->formFactory()->create(
            WorkflowDefinitionFormType::class,
            $definition ?? new WorkflowDefinition('New workflow', 'new_workflow', 'draft', 'App\\Entity\\Subject'),
            ['section' => WorkflowDefinitionFormSection::General],
        );

        $context = [
            'form'    => $form->createView(),
            'title'   => $definition === null ? 'page.new_definition' : 'page.edit_definition',
            'section' => WorkflowDefinitionFormSection::General,
        ];
        if ($definition !== null) {
            $context['definition'] = $definition;
        }

        return $twig->render('@NowoWorkflowBundle/workflow_definition/form.html.twig', $context);
    }

    private function formFactory(): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addType(FormKitTestSupport::withMerger(new WorkflowDefinitionFormType()))
            ->getFormFactory();
    }
}
