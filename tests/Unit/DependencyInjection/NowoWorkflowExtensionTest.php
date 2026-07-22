<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Nowo\WorkflowBundle\DependencyInjection\NowoWorkflowExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension;
use Symfony\Bundle\TwigBundle\DependencyInjection\TwigExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class NowoWorkflowExtensionTest extends TestCase
{
    public function testLoadSetsParameters(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoWorkflowExtension());

        $extension = $container->getExtension('nowo_workflow');
        $extension->load([[
            'enabled'      => true,
            'connection'   => 'custom',
            'table_prefix' => 'wf_',
            'ui'           => [
                'path'           => '/wf',
                'default_locale' => 'fr',
                'locales'        => ['fr', 'en'],
            ],
        ]], $container);

        self::assertTrue($container->getParameter('nowo_workflow.enabled'));
        self::assertSame('custom', $container->getParameter('nowo_workflow.connection'));
        self::assertSame('wf_', $container->getParameter('nowo_workflow.table_prefix'));
        self::assertSame('/wf', $container->getParameter('nowo_workflow.ui.path'));
        self::assertSame('fr', $container->getParameter('nowo_workflow.ui.default_locale'));
        self::assertSame(['fr', 'en'], $container->getParameter('nowo_workflow.ui.locales'));
    }

    public function testPrependTwigFormThemesWhenTwigExtensionPresent(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoWorkflowExtension());
        $container->registerExtension(new TwigExtension());

        $extension = $container->getExtension('nowo_workflow');
        self::assertInstanceOf(NowoWorkflowExtension::class, $extension);
        $extension->prepend($container);

        $twigConfigs = $container->getExtensionConfig('twig');
        self::assertNotEmpty($twigConfigs);
        self::assertContains('bootstrap_5_layout.html.twig', $twigConfigs[0]['form_themes'] ?? []);
    }

    public function testPrependDoctrineMappingsWhenDoctrineExtensionPresent(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoWorkflowExtension());
        $container->registerExtension(new DoctrineExtension());

        $extension = $container->getExtension('nowo_workflow');
        self::assertInstanceOf(NowoWorkflowExtension::class, $extension);
        $extension->prepend($container);

        $doctrineConfigs = $container->getExtensionConfig('doctrine');
        self::assertNotEmpty($doctrineConfigs);
        self::assertArrayHasKey('NowoWorkflowBundle', $doctrineConfigs[0]['orm']['mappings'] ?? []);
    }

    public function testPrependTranslatorPathsWhenFrameworkExtensionPresent(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoWorkflowExtension());
        $container->registerExtension(new FrameworkExtension());

        $extension = $container->getExtension('nowo_workflow');
        self::assertInstanceOf(NowoWorkflowExtension::class, $extension);
        $extension->prepend($container);

        $frameworkConfigs = $container->getExtensionConfig('framework');
        self::assertNotEmpty($frameworkConfigs);
        self::assertNotEmpty($frameworkConfigs[0]['translator']['paths'] ?? []);
    }

    public function testGetAlias(): void
    {
        self::assertSame('nowo_workflow', (new NowoWorkflowExtension())->getAlias());
    }
}
