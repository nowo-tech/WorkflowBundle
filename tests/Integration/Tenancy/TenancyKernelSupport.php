<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Tenancy;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowTransition;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\DatabaseWorkflowRegistry;
use Nowo\WorkflowBundle\Service\WorkflowApplicator;
use Nowo\WorkflowBundle\Service\WorkflowVersioning;
use Nowo\WorkflowBundle\Tests\Integration\Tenancy\Domain\LeaveRequest;
use Nowo\WorkflowBundle\Tests\Support\Tenancy\AnyUserTokenListener;
use Nowo\WorkflowBundle\Tests\Support\Tenancy\RecordingAuditListener;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function str_replace;

/**
 * Helpers of the {@see TenancyTestKernel} tests. Requests go straight to `http_kernel` (no services resetter).
 */
trait TenancyKernelSupport
{
    private static function container(TenancyTestKernel $kernel): ContainerInterface
    {
        $container = $kernel->getContainer()->get('test.service_container');
        if (!$container instanceof ContainerInterface) {
            throw new RuntimeException('No test container.');
        }

        return $container;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function service(TenancyTestKernel $kernel, string $class, ?string $id = null): object
    {
        $service = self::container($kernel)->get($id ?? $class);
        if (!$service instanceof $class) {
            throw new RuntimeException('No ' . $class . '.');
        }

        return $service;
    }

    private static function boot(string $variant, bool $flushAfterApply = true): TenancyTestKernel
    {
        $kernel = new TenancyTestKernel($variant, false, $flushAfterApply);
        $kernel->boot();
        $doctrine = self::service($kernel, ManagerRegistry::class, 'doctrine');
        foreach ($doctrine->getManagers() as $manager) {
            if ($manager instanceof EntityManagerInterface) {
                (new SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());
            }
        }

        return $kernel;
    }

    private static function repository(TenancyTestKernel $kernel): WorkflowDefinitionRepository
    {
        return self::service($kernel, WorkflowDefinitionRepository::class);
    }

    private static function registry(TenancyTestKernel $kernel): DatabaseWorkflowRegistry
    {
        return self::service($kernel, DatabaseWorkflowRegistry::class, 'test.' . DatabaseWorkflowRegistry::class);
    }

    private static function applicator(TenancyTestKernel $kernel): WorkflowApplicator
    {
        return self::service($kernel, WorkflowApplicator::class, 'test.' . WorkflowApplicator::class);
    }

    private static function versioning(TenancyTestKernel $kernel): WorkflowVersioning
    {
        return self::service($kernel, WorkflowVersioning::class, 'test.' . WorkflowVersioning::class);
    }

    private static function audit(TenancyTestKernel $kernel): RecordingAuditListener
    {
        return self::service($kernel, RecordingAuditListener::class);
    }

    private static function hostManager(TenancyTestKernel $kernel): EntityManagerInterface
    {
        $manager = self::service($kernel, ManagerRegistry::class, 'doctrine')->getManager();
        if (!$manager instanceof EntityManagerInterface) {
            throw new RuntimeException('No default manager.');
        }

        return $manager;
    }

    /**
     * The global "leave" template: requested → submitted → approved / rejected, approve and reject need the
     * `leave.approve` permission, auto_approve is automatic when `days <= 2`.
     */
    private static function seedLeaveTemplate(TenancyTestKernel $kernel, string $condition = 'days <= 2', ?WorkflowTenant $tenant = null, bool $publish = true): WorkflowDefinition
    {
        $definition = new WorkflowDefinition('Leave approval', 'leave', 'requested', LeaveRequest::class);
        if ($tenant instanceof WorkflowTenant || !$publish) {
            $definition->startAsDraft($tenant);
            if ($publish) {
                $definition->publish();
            }
        }
        foreach (['requested', 'submitted', 'approved', 'rejected'] as $index => $place) {
            $definition->addPlace(new WorkflowPlace($place, null, $index));
        }
        $definition->addTransition(new WorkflowTransition('submit', ['requested'], ['submitted']));
        $definition->addTransition((new WorkflowTransition('approve', ['submitted'], ['approved']))->setGuardPermission('leave.approve'));
        $definition->addTransition((new WorkflowTransition('auto_approve', ['submitted'], ['approved']))->setAutomatic(true)->setGuardExpression($condition));
        $definition->addTransition((new WorkflowTransition('reject', ['submitted'], ['rejected']))->setGuardPermission('leave.approve'));
        self::repository($kernel)->save($definition);

        return $definition;
    }

    private static function persistLeave(TenancyTestKernel $kernel, LeaveRequest $leave): LeaveRequest
    {
        $manager = self::hostManager($kernel);
        $manager->persist($leave);
        $manager->flush();

        return $leave;
    }

    private static function csrf(TenancyTestKernel $kernel, string $intention): string
    {
        return self::service($kernel, CsrfTokenManagerInterface::class, 'security.csrf.token_manager')->getToken($intention)->getValue();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private static function send(TenancyTestKernel $kernel, string $path, ?string $user, string $method = 'GET', array $parameters = []): Response
    {
        $httpKernel = self::service($kernel, HttpKernelInterface::class, 'http_kernel');
        $request    = Request::create($path, $method, $parameters);
        if ($user !== null) {
            $request->headers->set(AnyUserTokenListener::HEADER, $user);
        }
        $response = $httpKernel->handle($request);
        if ($httpKernel instanceof TerminableInterface) {
            $httpKernel->terminate($request, $response);
        }

        return $response;
    }

    /**
     * A UI path of an organization (`global` = the templates scope).
     */
    private static function ui(string $organization, string $path = ''): string
    {
        return str_replace('{uuid}', $organization, TenancyTestKernel::PREFIX) . '/workflow' . $path;
    }
}
