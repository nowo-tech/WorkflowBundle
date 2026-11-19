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
use Nowo\WorkflowBundle\Contract\WorkflowRequestSearchNarrowerInterface;
use Nowo\WorkflowBundle\Event\WorkflowRequestAccessCheckEvent;
use Nowo\WorkflowBundle\Search\WorkflowRequestSearchCriteria;
use Nowo\WorkflowBundle\Search\WorkflowRequestSearchOptions;
use Nowo\WorkflowBundle\Tests\Support\TestManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Contracts\Translation\TranslatorInterface;

use function bin2hex;
use function is_string;
use function random_bytes;

use const PHP_VERSION_ID;

/**
 * Builds the SearchKit groups under test (subjects "leave" and "purchase") on SQLite (in memory) or on a real server (a DSN), with the query counter on
 * the connection, the test host's gate and its access listener.
 */
final class SearchRequestHarness
{
    public const ROUTE = 'app_request_show';

    public const LIST_ROUTE = 'app_request_index';

    public const PURCHASE_ROUTE = 'app_purchase_show';

    public readonly EntityManagerInterface $em;

    public readonly QueryCounter $counter;

    public readonly FakeRequestGate $gate;

    public readonly EventDispatcher $dispatcher;

    public readonly OwnerAccessListener $listener;

    public readonly TitleResolver $titles;

    /** The leave subject's narrower: the access listener's owner rule, in SQL (owner = viewer or shared). */
    public readonly OwnRequestsNarrower $ownerNarrower;

    public function __construct(?string $url = null)
    {
        $this->counter = new QueryCounter();
        $config        = ORMSetup::createAttributeMetadataConfiguration([__DIR__], true);
        $config->setMiddlewares([new QueryCountingMiddleware($this->counter)]);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $params = is_string($url) ? self::urlParams($url) : ['driver' => 'pdo_sqlite', 'memory' => true];

        $this->em            = new EntityManager(DriverManager::getConnection($params, $config), $config);
        $this->gate          = new FakeRequestGate();
        $this->dispatcher    = new EventDispatcher();
        $this->listener      = new OwnerAccessListener($this->em->getConnection());
        $this->titles        = new TitleResolver($this->em->getConnection());
        $this->ownerNarrower = new OwnRequestsNarrower(static function (WorkflowRequestSearchCriteria $criteria, string $viewer): void {
            $criteria->whereIn('ownerIdentifier', [$viewer, '']);
        });
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
     * The options of a test subject: "leave" (search text, title resolver, enum status, integer ids and tenant, every
     * SQL filter) or "purchase" (title field only, plain status, string ids and tenant). A small window.
     *
     * @param array<string, mixed> $override
     */
    public static function options(string $subject = 'leave', array $override = []): WorkflowRequestSearchOptions
    {
        $base = $subject === 'leave'
            ? [
                'subject_class'     => SearchRequest::class,
                'route'             => self::ROUTE,
                'gate'              => 'app.request_gate',
                'title_resolver'    => 'app.leave_titles',
                'narrower'          => 'app.leave_narrower',
                'group'             => 'workflow_leave_requests',
                'search_text_field' => 'searchText',
                'tenant_field'      => 'tenantId',
                'archived_field'    => 'archivedAt',
                'order_field'       => 'updatedAt',
                'excluded_statuses' => ['draft'],
                'see_all_route'     => self::LIST_ROUTE,
                'max_candidates'    => 20,
            ]
            : [
                'subject_class'  => PurchaseRequest::class,
                'route'          => self::PURCHASE_ROUTE,
                'gate'           => 'app.request_gate',
                'title_field'    => 'title',
                'status_field'   => 'place',
                'tenant_field'   => 'tenantKey',
                'archived_field' => 'deletedAt',
                'order_field'    => 'createdAt',
                'label'          => 'search.purchases',
                'label_domain'   => 'app',
                'sensitive'      => false,
                'max_candidates' => 20,
            ];

        return WorkflowRequestSearchOptions::fromConfig($subject, $override + $base);
    }

    public function definition(string $subject = 'leave', ?WorkflowRequestSearchOptions $options = null, ?TranslatorInterface $translator = null, bool $withDispatcher = true, ?WorkflowRequestSearchNarrowerInterface $narrower = null, ?LoggerInterface $logger = null): WorkflowRequestSearchDefinition
    {
        $options ??= self::options($subject);

        return new WorkflowRequestSearchDefinition(
            new TestManagerRegistry($this->em),
            $this->gate,
            self::urls(),
            $options,
            $withDispatcher ? $this->dispatcher : null,
            $translator,
            $options->titleResolver === null ? null : $this->titles,
            $narrower ?? ($options->narrower === null ? null : $this->ownerNarrower),
            $logger,
        );
    }

    public static function urls(): UrlGenerator
    {
        $routes = new RouteCollection();
        $routes->add(self::ROUTE, new Route('/requests/{id}'));
        $routes->add(self::LIST_ROUTE, new Route('/requests'));
        $routes->add(self::PURCHASE_ROUTE, new Route('/purchases/{id}'));

        return new UrlGenerator($routes, new RequestContext());
    }

    public function insert(int|string $tenant, string $searchText, string $owner = '', bool $archived = false, SearchRequestStatus $status = SearchRequestStatus::Pending, string $body = '', ?string $title = 'title'): int
    {
        $request = new SearchRequest((int) $tenant, $searchText, $title, $status, $owner, $body, 'approver note ' . $body);
        if ($archived) {
            $request->archive();
        }
        $this->em->persist($request);
        $this->em->flush();
        $this->em->clear();

        return (int) $request->getId();
    }

    public function insertPurchase(int|string $tenant, string $title, string $owner = '', bool $deleted = false, string $place = 'open'): string
    {
        $request = new PurchaseRequest('p' . bin2hex(random_bytes(8)), (string) $tenant, $title, $place, $owner);
        if ($deleted) {
            $request->delete();
        }
        $this->em->persist($request);
        $this->em->flush();
        $this->em->clear();

        return $request->getId();
    }
}
