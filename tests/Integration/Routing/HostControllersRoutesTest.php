<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Routing;

use Nowo\WorkflowBundle\Controller\DashboardController;
use Nowo\WorkflowBundle\Tests\Support\HostControllersRoutesKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\RouterInterface;

use function class_exists;
use function is_string;
use function str_starts_with;
use function sys_get_temp_dir;

/**
 * REQ-SF-004 rule 7: a host whose routing is only `routing.controllers` (Symfony >= 7.3 default
 * `config/routes.yaml`) must not get the Workflow admin routes without importing the bundle `routes.yaml`.
 */
final class HostControllersRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('routing.controllers requires symfony/routing >= 7.3.');
        }
    }

    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . HostControllersRoutesKernel::CACHE_ROOT);
    }

    /** Removed once: a booted container keeps autoloading its lazy-ghost classes from its cache directory. */
    public static function tearDownAfterClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . HostControllersRoutesKernel::CACHE_ROOT);
    }

    public function testRoutingControllersImportDoesNotRegisterBundleRoutes(): void
    {
        $kernel = new HostControllersRoutesKernel();
        $kernel->boot();

        try {
            $container     = $kernel->getContainer();
            $testContainer = $container->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $testContainer);
            self::assertTrue($testContainer->has(DashboardController::class), 'The dashboard controller must be registered.');

            $router = $container->get('router');
            self::assertInstanceOf(RouterInterface::class, $router);

            foreach ($router->getRouteCollection()->all() as $name => $route) {
                self::assertFalse(str_starts_with($name, 'nowo_workflow_'), 'Bundle route "' . $name . '" must stay opt-in.');
                $controller = $route->getDefault('_controller');
                self::assertFalse(
                    is_string($controller) && str_starts_with($controller, 'Nowo\\WorkflowBundle\\'),
                    'Route "' . $name . '" points to a bundle controller.',
                );
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public function testImportedBundleRoutesServeThroughTheControllerContainer(): void
    {
        $kernel = new HostControllersRoutesKernel(importBundleRoutes: true);
        $kernel->boot();

        try {
            $router = $kernel->getContainer()->get('router');
            self::assertInstanceOf(RouterInterface::class, $router);
            self::assertSame('/workflow/', $router->getRouteCollection()->get('nowo_workflow_dashboard')?->getPath());

            // redirectToRoute() goes through the AbstractController service locator (container.service_subscriber).
            $response = $kernel->handle(Request::create('/workflow/locale/es'));
            self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
            self::assertSame('/workflow/', $response->headers->get('Location'));
        } finally {
            $kernel->shutdown();
        }
    }
}
