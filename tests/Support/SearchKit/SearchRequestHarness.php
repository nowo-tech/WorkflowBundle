<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\SearchKit;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\SearchKitBundle\Test\QueryCounter;
use Nowo\SearchKitBundle\Test\QueryCountingMiddleware;
use Nowo\WorkflowBundle\Bridge\SearchKit\WorkflowRequestSearchDefinition;
use Nowo\WorkflowBundle\Event\WorkflowRequestAccessCheckEvent;
use Nowo\WorkflowBundle\Search\WorkflowRequestSearchOptions;
use Nowo\WorkflowBundle\Tests\Support\TestManagerRegistry;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_string;

use const PHP_VERSION_ID;

/**
 * Builds the SearchKit group under test on SQLite (in memory) or on a real server (a DSN), with the query counter on
 * the connection, the test host's gate and its access listener.
 */
final class SearchRequestHarness
{
    public const ROUTE = 'app_request_show';

    public const LIST_ROUTE = 'app_request_index';

    public readonly EntityManagerInterface $em;

    public readonly QueryCounter $counter;

    public readonly FakeRequestGate $gate;

    public readonly EventDispatcher $dispatcher;

    public readonly OwnerAccessListener $listener;

    public function __construct(?string $url = null)
    {
        $this->counter = new QueryCounter();
        $config        = ORMSetup::createAttributeMetadataConfiguration([__DIR__], true);
        $config->setMiddlewares([new QueryCountingMiddleware($this->counter)]);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $params = is_string($url) ? self::urlParams($url) : ['driver' => 'pdo_sqlite', 'memory' => true];

        $this->em         = new EntityManager(DriverManager::getConnection($params, $config), $config);
        $this->gate       = new FakeRequestGate();
        $this->dispatcher = new EventDispatcher();
        $this->listener   = new OwnerAccessListener($this->em->getConnection());
        $this->dispatcher->addListener(WorkflowRequestAccessCheckEvent::class, $this->listener);
        (new SchemaTool($this->em))->updateSchema($this->em->getMetadataFactory()->getAllMetadata());
    }

    /**
     * @return array<string, mixed>
     */
    public static function urlParams(string $url): array
    {
        return (new DsnParser(['pdo-mysql' => 'pdo_mysql', 'mysql' => 'pdo_mysql', 'pdo-pgsql' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql']))->parse($url);
    }

    /** Drops the test table (real servers, after the class). */
    public function dropSchema(): void
    {
        (new SchemaTool($this->em))->dropSchema($this->em->getMetadataFactory()->getAllMetadata());
        $this->em->getConnection()->close();
    }

    /**
     * The options of the tests: every SQL filter on, a small window.
     *
     * @param array<string, mixed> $override
     */
    public static function options(array $override = []): WorkflowRequestSearchOptions
    {
        return WorkflowRequestSearchOptions::fromConfig($override + [
            'subject_class'     => SearchRequest::class,
            'route'             => self::ROUTE,
            'gate'              => 'app.request_gate',
            'tenant_field'      => 'tenantId',
            'archived_field'    => 'archivedAt',
            'order_field'       => 'updatedAt',
            'excluded_statuses' => ['draft'],
            'see_all_route'     => self::LIST_ROUTE,
            'max_candidates'    => 20,
        ]);
    }

    public function definition(?WorkflowRequestSearchOptions $options = null, ?TranslatorInterface $translator = null, bool $withDispatcher = true): WorkflowRequestSearchDefinition
    {
        return new WorkflowRequestSearchDefinition(
            new TestManagerRegistry($this->em),
            $this->gate,
            self::urls(),
            $options ?? self::options(),
            $withDispatcher ? $this->dispatcher : null,
            $translator,
        );
    }

    public static function urls(): UrlGenerator
    {
        $routes = new RouteCollection();
        $routes->add(self::ROUTE, new Route('/requests/{id}'));
        $routes->add(self::LIST_ROUTE, new Route('/requests'));

        return new UrlGenerator($routes, new RequestContext());
    }

    public function insert(int|string $tenant, ?string $title, string $owner = '', bool $archived = false, SearchRequestStatus $status = SearchRequestStatus::Pending, string $body = ''): int
    {
        $request = new SearchRequest((int) $tenant, $title, $status, $owner, $body, 'approver note ' . $body);
        if ($archived) {
            $request->archive();
        }
        $this->em->persist($request);
        $this->em->flush();
        $this->em->clear();

        return (int) $request->getId();
    }
}
