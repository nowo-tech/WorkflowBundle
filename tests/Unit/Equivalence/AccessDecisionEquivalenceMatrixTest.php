<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Equivalence;

use Closure;
use Nowo\WorkflowBundle\Contract\WorkflowUiAccessCheckerInterface;
use Nowo\WorkflowBundle\Contract\WorkflowUiAccessPolicyInterface;
use Nowo\WorkflowBundle\DependencyInjection\Compiler\WorkflowUiSecurityPass;
use Nowo\WorkflowBundle\EventListener\WorkflowUiAccessSubscriber;
use Nowo\WorkflowBundle\Model\WorkflowActor;
use Nowo\WorkflowBundle\Model\WorkflowUiAccessCheck;
use Nowo\WorkflowBundle\Service\AllowAllWorkflowUiAccessChecker;
use Nowo\WorkflowBundle\Service\DenyAllWorkflowUiAccessChecker;
use Nowo\WorkflowBundle\Service\RoleBasedWorkflowUiAccessChecker;
use Nowo\WorkflowBundle\Tests\Support\Access\AccessPredicates;
use Nowo\WorkflowBundle\Tests\Support\Access\DualPredicate;
use Nowo\WorkflowBundle\Tests\Support\Access\LegacyPredicateChecker;
use Nowo\WorkflowBundle\Tests\Support\Access\LegacyPredicateCheckerV1130;
use Nowo\WorkflowBundle\Tests\Support\Access\PassThroughLegacyDecorator;
use Nowo\WorkflowBundle\Tests\Support\Access\PolicyPredicate;
use Nowo\WorkflowBundle\Tests\Support\Legacy\RoleBasedWorkflowUiAccessCheckerV1130;
use Nowo\WorkflowBundle\Tests\Support\Legacy\WorkflowUiAccessCheckerInterfaceV1130;
use Nowo\WorkflowBundle\Tests\Support\Legacy\WorkflowUiAccessSubscriberV1130;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\Voter\RoleHierarchyVoter;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Throwable;

use function array_count_values;
use function array_slice;
use function count;
use function explode;
use function implode;
use function sprintf;
use function str_starts_with;
use function substr;

/**
 * 1.14 (REQ-ARCH-001 rule 3, immediate audit): the Workflow UI access decision is identical through the 1.14
 * framework-free port, through the deprecated port, through a service on both ports and with a host decorator on the
 * deprecated port, and identical to the verbatim v1.13.0 subscriber and role-based checker
 * ({@see WorkflowUiAccessSubscriberV1130}, {@see RoleBasedWorkflowUiAccessCheckerV1130}).
 *
 * Matrix: web UI enabled / disabled × main / sub-request × 7 routes (bundle routes, a bundle controller under a
 * foreign route name, a route-less bundle controller, a host route) × 7 tokens (none, a null token, a user, an
 * editor, an admin, a hierarchy-reached admin, an admin impersonating a user) × 10 policies (the built-in role-based
 * policy with three role lists, allow-all, deny-all, five host rules reading the method, route, path, route
 * parameters, user, roles and impersonator). Each cell records the outcome of kernel.request + kernel.controller
 * (pass / 404 / 403 / exception class) and every variant must match the 1.13.0 reference.
 *
 * Mutation check: the same comparison run against deliberately broken 1.14 policies (VO without the actor, the path,
 * the route parameters, the impersonator; a role-based policy that grants on an empty role list; a policy that flips
 * one host rule) must report differences, so a regression in any input of the VO is caught by this matrix.
 */
final class AccessDecisionEquivalenceMatrixTest extends TestCase
{
    private const DASHBOARD_CONTROLLER  = 'Nowo\\WorkflowBundle\\Controller\\DashboardController::index';
    private const DEFINITION_CONTROLLER = 'Nowo\\WorkflowBundle\\Controller\\WorkflowDefinitionController';

    #[Test]
    public function everyDecisionMatchesTheV1130Reference(): void
    {
        $outcomes    = [];
        $differences = $this->compare(static fn (string $policy, string $variant, TokenStorage $tokens, Closure $authorization, bool $enabled): ?WorkflowUiAccessSubscriber => null, $outcomes);

        self::assertSame([], array_slice($differences, 0, 10), sprintf('%d differences', count($differences)));
        // 2 enabled × 7 routes × 2 request types × 7 tokens × 10 policies, each compared with 4 variants (+ the unwired
        // subscriber for deny-all): 8,036 pairs.
        self::assertCount(1960, $outcomes, 'reference cells');
        $distribution = array_count_values($outcomes);
        self::assertArrayHasKey('pass', $distribution);
        self::assertArrayHasKey('403', $distribution);
        self::assertArrayHasKey('404', $distribution);
    }

    /**
     * @return iterable<string, array{Closure(string, string, TokenStorage, Closure, bool): ?WorkflowUiAccessSubscriber}>
     */
    public static function mutants(): iterable
    {
        $strip = static fn (Closure $mutate): Closure => static function (string $policy, string $variant, TokenStorage $tokens, Closure $authorization, bool $enabled) use ($mutate): ?WorkflowUiAccessSubscriber {
            if ($variant !== 'policy') {
                return null;
            }
            $inner = self::policyFor($policy, $authorization);

            return new WorkflowUiAccessSubscriber(null, $enabled, new class($inner, $mutate) implements WorkflowUiAccessPolicyInterface {
                public function __construct(private readonly WorkflowUiAccessPolicyInterface $inner, private readonly Closure $mutate)
                {
                }

                public function allows(WorkflowUiAccessCheck $check): bool
                {
                    $mutated = ($this->mutate)($check);

                    return $this->inner->allows($mutated);
                }
            }, $tokens);
        };

        yield 'VO without the actor' => [$strip(static fn (WorkflowUiAccessCheck $c): WorkflowUiAccessCheck => new WorkflowUiAccessCheck($c->method, $c->routeName, $c->controller, null, $c->path(), $c->routeParams()))];
        yield 'VO without the path' => [$strip(static fn (WorkflowUiAccessCheck $c): WorkflowUiAccessCheck => new WorkflowUiAccessCheck($c->method, $c->routeName, $c->controller, $c->actor, '', $c->routeParams()))];
        yield 'VO without the route parameters' => [$strip(static fn (WorkflowUiAccessCheck $c): WorkflowUiAccessCheck => new WorkflowUiAccessCheck($c->method, $c->routeName, $c->controller, $c->actor, $c->path(), []))];
        yield 'VO without the route name' => [$strip(static fn (WorkflowUiAccessCheck $c): WorkflowUiAccessCheck => new WorkflowUiAccessCheck($c->method, null, $c->controller, $c->actor, $c->path(), $c->routeParams()))];
        yield 'VO with GET for every method' => [$strip(static fn (WorkflowUiAccessCheck $c): WorkflowUiAccessCheck => new WorkflowUiAccessCheck('GET', $c->routeName, $c->controller, $c->actor, $c->path(), $c->routeParams()))];
        yield 'actor without the impersonator' => [$strip(static fn (WorkflowUiAccessCheck $c): WorkflowUiAccessCheck => new WorkflowUiAccessCheck($c->method, $c->routeName, $c->controller, $c->actor instanceof WorkflowActor ? new WorkflowActor($c->actor->userIdentifier, $c->actor->roles) : null, $c->path(), $c->routeParams()))];
        yield 'actor without roles' => [$strip(static fn (WorkflowUiAccessCheck $c): WorkflowUiAccessCheck => new WorkflowUiAccessCheck($c->method, $c->routeName, $c->controller, $c->actor instanceof WorkflowActor ? new WorkflowActor($c->actor->userIdentifier, [], null, $c->actor->impersonatorIdentifier) : null, $c->path(), $c->routeParams()))];
        yield 'role-based grants on an empty role list' => [static function (string $policy, string $variant, TokenStorage $tokens, Closure $authorization, bool $enabled): ?WorkflowUiAccessSubscriber {
            if ($variant !== 'policy' || $policy !== 'roles:') {
                return null;
            }

            return new WorkflowUiAccessSubscriber(null, $enabled, new AllowAllWorkflowUiAccessChecker(), $tokens);
        }];
        yield 'deprecated port ignored in both mode' => [static function (string $policy, string $variant, TokenStorage $tokens, Closure $authorization, bool $enabled): ?WorkflowUiAccessSubscriber {
            if ($variant !== 'both') {
                return null;
            }

            return new WorkflowUiAccessSubscriber(null, $enabled, new AllowAllWorkflowUiAccessChecker(), $tokens);
        }];
        yield 'policy ignored in both mode' => [static function (string $policy, string $variant, TokenStorage $tokens, Closure $authorization, bool $enabled): ?WorkflowUiAccessSubscriber {
            if ($variant !== 'both') {
                return null;
            }

            return new WorkflowUiAccessSubscriber(new AllowAllWorkflowUiAccessChecker(), $enabled, null, $tokens);
        }];
        yield 'no port wired grants' => [static function (string $policy, string $variant, TokenStorage $tokens, Closure $authorization, bool $enabled): ?WorkflowUiAccessSubscriber {
            return $variant === 'legacy' && $policy === 'deny' ? new WorkflowUiAccessSubscriber(new AllowAllWorkflowUiAccessChecker(), $enabled) : null;
        }];
    }

    /**
     * @param Closure(string, string, TokenStorage, Closure, bool): ?WorkflowUiAccessSubscriber $mutant
     */
    #[Test]
    #[DataProvider('mutants')]
    public function theMatrixCatchesEveryMutant(Closure $mutant): void
    {
        $outcomes = [];
        self::assertNotSame([], $this->compare($mutant, $outcomes), 'the mutant must be detected');
    }

    /**
     * @param Closure(string, string, TokenStorage, Closure, bool): ?WorkflowUiAccessSubscriber $override replaces a 1.14 variant (mutants)
     * @param list<string> $outcomes reference outcomes (filled)
     *
     * @return list<string>
     */
    private function compare(Closure $override, array &$outcomes): array
    {
        $differences = [];
        foreach ([true, false] as $enabled) {
            foreach ($this->requests() as $requestName => $requestFactory) {
                foreach ([HttpKernelInterface::MAIN_REQUEST, HttpKernelInterface::SUB_REQUEST] as $type) {
                    foreach ($this->tokens() as $tokenName => $token) {
                        $tokens = new TokenStorage();
                        $tokens->setToken($token);
                        $authorization = static function () use ($tokens): AuthorizationChecker {
                            return new AuthorizationChecker($tokens, new AccessDecisionManager([new RoleHierarchyVoter(new RoleHierarchy(['ROLE_SUPER_ADMIN' => ['ROLE_ADMIN']]))]));
                        };
                        foreach ($this->policies() as $policy) {
                            $reference  = $this->outcome($this->reference($policy, $tokens, $authorization, $enabled), $requestFactory, $type);
                            $outcomes[] = $reference;
                            // Fail closed: a subscriber with no port wired decides like deny-all.
                            foreach ($policy === 'deny' ? ['policy', 'legacy', 'dual', 'both', 'unwired'] : ['policy', 'legacy', 'dual', 'both'] as $variant) {
                                $subscriber = $override($policy, $variant, $tokens, $authorization, $enabled) ?? $this->variant($policy, $variant, $tokens, $authorization, $enabled);
                                $actual     = $this->outcome($subscriber, $requestFactory, $type);
                                if ($actual !== $reference) {
                                    $differences[] = implode(' | ', [$enabled ? 'enabled' : 'disabled', $requestName, $type === HttpKernelInterface::MAIN_REQUEST ? 'main' : 'sub', $tokenName, $policy, $variant, 'expected ' . $reference, 'got ' . $actual]);
                                }
                            }
                        }
                    }
                }
            }
        }

        return $differences;
    }

    private function outcome(WorkflowUiAccessSubscriber|WorkflowUiAccessSubscriberV1130 $subscriber, Closure $requestFactory, int $type): string
    {
        /** @var Request $request */
        $request = $requestFactory();
        $kernel  = $this->createStub(HttpKernelInterface::class);

        try {
            $subscriber->onKernelRequest(new RequestEvent($kernel, $request, $type));
            $subscriber->onKernelController(new ControllerEvent($kernel, static fn (): string => 'ok', $request, $type));
        } catch (NotFoundHttpException) {
            return '404';
        } catch (AccessDeniedHttpException) {
            return '403';
        } catch (Throwable $exception) {
            return 'error:' . $exception::class;
        }

        return 'pass';
    }

    /**
     * @return list<string>
     */
    private function policies(): array
    {
        $policies = ['roles:', 'roles:ROLE_ADMIN', 'roles:ROLE_EDITOR,ROLE_ADMIN', 'allow', 'deny'];
        foreach (AccessPredicates::NAMES as $name) {
            $policies[] = 'custom:' . $name;
        }

        return $policies;
    }

    /**
     * @return list<string>
     */
    private static function roles(string $policy): array
    {
        $list = substr($policy, 6);

        return $list === '' ? [] : explode(',', $list);
    }

    private function reference(string $policy, TokenStorage $tokens, Closure $authorization, bool $enabled): WorkflowUiAccessSubscriberV1130
    {
        $checker = match (true) {
            str_starts_with($policy, 'roles:') => new RoleBasedWorkflowUiAccessCheckerV1130(self::roles($policy), $authorization()),
            $policy === 'allow'                => new class implements WorkflowUiAccessCheckerInterfaceV1130 {
                public function isGranted(Request $request): bool
                {
                    return true;
                }
            },
            $policy === 'deny' => new class implements WorkflowUiAccessCheckerInterfaceV1130 {
                public function isGranted(Request $request): bool
                {
                    return false;
                }
            },
            default => new LegacyPredicateCheckerV1130(substr($policy, 7), $tokens),
        };

        return new WorkflowUiAccessSubscriberV1130($checker, $enabled);
    }

    private static function policyFor(string $policy, Closure $authorization): WorkflowUiAccessPolicyInterface
    {
        return match (true) {
            str_starts_with($policy, 'roles:') => new RoleBasedWorkflowUiAccessChecker(self::roles($policy), $authorization()),
            $policy === 'allow'                => new AllowAllWorkflowUiAccessChecker(),
            $policy === 'deny'                 => new DenyAllWorkflowUiAccessChecker(),
            default                            => new PolicyPredicate(substr($policy, 7)),
        };
    }

    private function legacyFor(string $policy, TokenStorage $tokens, Closure $authorization): WorkflowUiAccessCheckerInterface
    {
        return match (true) {
            str_starts_with($policy, 'roles:') => new RoleBasedWorkflowUiAccessChecker(self::roles($policy), $authorization()),
            $policy === 'allow'                => new AllowAllWorkflowUiAccessChecker(),
            $policy === 'deny'                 => new DenyAllWorkflowUiAccessChecker(),
            default                            => new LegacyPredicateChecker(substr($policy, 7), $tokens),
        };
    }

    /**
     * The 1.14 subscriber as {@see WorkflowUiSecurityPass} wires it
     * for each host shape.
     */
    private function variant(string $policy, string $variant, TokenStorage $tokens, Closure $authorization, bool $enabled): WorkflowUiAccessSubscriber
    {
        return match ($variant) {
            // 1.14 port only (built-in policy, or a host service on the new port).
            'policy' => new WorkflowUiAccessSubscriber(null, $enabled, self::policyFor($policy, $authorization), $tokens),
            // Deprecated port only (a host service implementing only the deprecated interface, or a factory service).
            'legacy' => new WorkflowUiAccessSubscriber($this->legacyFor($policy, $tokens, $authorization), $enabled),
            // A host service on both ports: asked through the new one (its deprecated method throws).
            'dual'    => new WorkflowUiAccessSubscriber(null, $enabled, str_starts_with($policy, 'custom:') ? new DualPredicate(substr($policy, 7)) : self::policyFor($policy, $authorization), $tokens),
            'unwired' => new WorkflowUiAccessSubscriber(null, $enabled),
            // A host decorator of the deprecated port with the same rule on the new port: both asked.
            default => new WorkflowUiAccessSubscriber(new PassThroughLegacyDecorator($this->legacyFor($policy, $tokens, $authorization)), $enabled, self::policyFor($policy, $authorization), $tokens),
        };
    }

    /**
     * @return array<string, Closure(): Request>
     */
    private function requests(): array
    {
        $make = static function (string $method, string $path, ?string $route, ?string $controller, array $params = []): Closure {
            return static function () use ($method, $path, $route, $controller, $params): Request {
                $request = Request::create($path, $method);
                if ($route !== null) {
                    $request->attributes->set('_route', $route);
                }
                if ($controller !== null) {
                    $request->attributes->set('_controller', $controller);
                }
                $request->attributes->set('_route_params', $params);

                return $request;
            };
        };

        return [
            'dashboard'     => $make('GET', '/workflow', 'nowo_workflow_dashboard', self::DASHBOARD_CONTROLLER),
            'show 13'       => $make('GET', '/workflow/definitions/13', 'nowo_workflow_definition_show', self::DEFINITION_CONTROLLER . '::show', ['id' => '13']),
            'show 7'        => $make('GET', '/workflow/definitions/7', 'nowo_workflow_definition_show', self::DEFINITION_CONTROLLER . '::show', ['id' => '7']),
            'delete 7'      => $make('POST', '/workflow/definitions/7/delete', 'nowo_workflow_definition_delete', self::DEFINITION_CONTROLLER . '::delete', ['id' => '7']),
            'foreign name'  => $make('GET', '/cms/definitions', 'cms_definitions', '\\' . self::DEFINITION_CONTROLLER . '::index'),
            'no route name' => $make('GET', '/workflow/definitions', null, self::DEFINITION_CONTROLLER . '::index'),
            'host route'    => $make('GET', '/blog', 'app_blog', 'App\\Controller\\BlogController::index'),
        ];
    }

    /**
     * @return array<string, TokenInterface|null>
     */
    private function tokens(): array
    {
        $token = static fn (string $user, array $roles): UsernamePasswordToken => new UsernamePasswordToken(new InMemoryUser($user, null, $roles), 'main', $roles);
        $admin = $token('admin', ['ROLE_ADMIN']);

        return [
            'no token'      => null,
            'null token'    => new NullToken(),
            'alice user'    => $token('alice', ['ROLE_USER']),
            'bob editor'    => $token('bob', ['ROLE_EDITOR', 'ROLE_USER']),
            'admin'         => $admin,
            'root (super)'  => $token('root', ['ROLE_SUPER_ADMIN']),
            'admin as carl' => new SwitchUserToken(new InMemoryUser('carl', null, ['ROLE_ADMIN']), 'main', ['ROLE_ADMIN', 'IS_IMPERSONATOR'], $admin),
        ];
    }
}
