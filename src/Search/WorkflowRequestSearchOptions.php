<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Search;

use InvalidArgumentException;

use function implode;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function max;
use function min;
use function preg_match;
use function sprintf;

/**
 * The validated `nowo_workflow.search_kit` options of the SearchKit group of the workflow requests (since 1.21).
 *
 * The request class is the host's Doctrine ORM entity whose marking property holds the status (one place, a state
 * machine). Every `*_field` is a mapped field of that entity (not an association): the bundle builds the DQL from
 * them, so they are checked here (letters, digits and `_`) and again against the entity's metadata on every search.
 */
final readonly class WorkflowRequestSearchOptions
{
    /** SearchKit group key rule (a lower-case letter, then up to 63 of `a-z`, `0-9`, `_`, `-`). */
    public const GROUP_PATTERN = '/^[a-z][a-z0-9_-]{0,63}\z/';

    /** A mapped field name. */
    public const FIELD_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,63}\z/';

    /** A route name or route parameter name. */
    public const ROUTE_PATTERN = '/^[A-Za-z0-9_.:-]{1,128}\z/';

    public const DEFAULT_GROUP = 'workflow_requests';

    /** SearchKit's bounds of the candidate window. */
    public const MAX_CANDIDATES = 1000;

    /**
     * @param list<string> $excludedStatuses statuses (place names) never searched, e.g. drafts or cancelled requests
     * @param array<string, string> $routeParameters fixed parameters of the request route
     * @param array<string, string> $seeAllParameters fixed parameters of the list route
     */
    public function __construct(
        public string $subjectClass,
        public string $route,
        public string $group = self::DEFAULT_GROUP,
        public string $idField = 'id',
        public string $titleField = 'title',
        public string $statusField = 'status',
        public ?string $tenantField = null,
        public ?string $archivedField = null,
        public ?string $orderField = null,
        public array $excludedStatuses = [],
        public string $routeIdParameter = 'id',
        public array $routeParameters = [],
        public ?string $seeAllRoute = null,
        public array $seeAllParameters = [],
        public ?string $statusLabelDomain = null,
        public string $statusLabelPrefix = '',
        public ?string $icon = 'tabler:checklist',
        public bool $sensitive = true,
        public int $maxCandidates = 100,
        public int $minLength = 0,
    ) {
        if ($subjectClass === '') {
            throw new InvalidArgumentException('nowo_workflow.search_kit.subject_class must be the request entity class.');
        }
        self::check(self::GROUP_PATTERN, $group, 'group');
        foreach (['id_field' => $idField, 'title_field' => $titleField, 'status_field' => $statusField, 'tenant_field' => $tenantField, 'archived_field' => $archivedField, 'order_field' => $orderField] as $name => $field) {
            if ($field !== null) {
                self::check(self::FIELD_PATTERN, $field, $name);
            }
        }
        foreach (['route' => $route, 'route_id_parameter' => $routeIdParameter, 'see_all_route' => $seeAllRoute] as $name => $value) {
            if ($value !== null) {
                self::check(self::ROUTE_PATTERN, $value, $name);
            }
        }
        foreach ($excludedStatuses as $status) {
            if ($status === '') {
                throw new InvalidArgumentException('nowo_workflow.search_kit.excluded_statuses must not hold an empty status.');
            }
        }
        if ($maxCandidates < 1 || $maxCandidates > self::MAX_CANDIDATES || $minLength < 0) {
            throw new InvalidArgumentException(sprintf('nowo_workflow.search_kit.max_candidates must be 1 to %d and min_length at least 0.', self::MAX_CANDIDATES));
        }
    }

    /**
     * The options of the processed `nowo_workflow.search_kit` configuration.
     *
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException when a required option is missing or a value is invalid
     */
    public static function fromConfig(array $config): self
    {
        $missing = [];
        foreach (['subject_class', 'route', 'gate'] as $required) {
            if (!is_string($config[$required] ?? null) || $config[$required] === '') {
                $missing[] = $required;
            }
        }
        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf('nowo_workflow.search_kit is enabled: set %s.', implode(', ', $missing)));
        }

        return new self(
            subjectClass: self::string($config, 'subject_class'),
            route: self::string($config, 'route'),
            group: self::string($config, 'group', self::DEFAULT_GROUP),
            idField: self::string($config, 'id_field', 'id'),
            titleField: self::string($config, 'title_field', 'title'),
            statusField: self::string($config, 'status_field', 'status'),
            tenantField: self::nullableString($config, 'tenant_field'),
            archivedField: self::nullableString($config, 'archived_field'),
            orderField: self::nullableString($config, 'order_field'),
            excludedStatuses: self::strings($config, 'excluded_statuses'),
            routeIdParameter: self::string($config, 'route_id_parameter', 'id'),
            routeParameters: self::map($config, 'route_parameters'),
            seeAllRoute: self::nullableString($config, 'see_all_route'),
            seeAllParameters: self::map($config, 'see_all_parameters'),
            statusLabelDomain: self::nullableString($config, 'status_label_domain'),
            statusLabelPrefix: self::string($config, 'status_label_prefix', ''),
            icon: self::nullableString($config, 'icon'),
            sensitive: !is_bool($config['sensitive'] ?? null) || $config['sensitive'],
            maxCandidates: is_int($config['max_candidates'] ?? null) ? $config['max_candidates'] : 100,
            minLength: is_int($config['min_length'] ?? null) ? $config['min_length'] : 0,
        );
    }

    /** The candidate window SearchKit reads (1 to 1000). */
    public function candidates(): int
    {
        return max(1, min($this->maxCandidates, self::MAX_CANDIDATES));
    }

    private static function check(string $pattern, string $value, string $name): void
    {
        if (preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('nowo_workflow.search_kit.%s is not valid: "%s".', $name, $value));
        }
    }

    /** @param array<string, mixed> $config */
    private static function string(array $config, string $key, string $default = ''): string
    {
        return is_string($config[$key] ?? null) ? $config[$key] : $default;
    }

    /** @param array<string, mixed> $config */
    private static function nullableString(array $config, string $key): ?string
    {
        return is_string($config[$key] ?? null) && $config[$key] !== '' ? $config[$key] : null;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private static function strings(array $config, string $key): array
    {
        $values = [];
        foreach (is_array($config[$key] ?? null) ? $config[$key] : [] as $value) {
            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, string>
     */
    private static function map(array $config, string $key): array
    {
        $values = [];
        foreach (is_array($config[$key] ?? null) ? $config[$key] : [] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                self::check(self::ROUTE_PATTERN, $name, $key);
                $values[$name] = $value;
            }
        }

        return $values;
    }
}
