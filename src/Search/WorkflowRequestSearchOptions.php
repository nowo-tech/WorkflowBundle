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
use function str_starts_with;

/**
 * The validated options of one subject of `nowo_workflow.search_kit.subjects` (since 1.21): one SearchKit group of
 * workflow requests (e.g. leave requests).
 *
 * The request class is the host's Doctrine ORM entity. Every `*_field` is a mapped field of that entity (not an
 * association): the bundle builds the DQL from them, so they are checked here (letters, digits and `_`) and again
 * against the entity's metadata on every search.
 *
 * - Matching: `search_text_field` (a viewer-neutral column the host maintains) or, without it, `title_field`, plus
 *   the status (the stored place name or enum value).
 * - Display: titles from the subject's `title_resolver` (batch, visible ids only) or, without it, `title_field`.
 */
final readonly class WorkflowRequestSearchOptions
{
    /** A subject key (the config key; carried by the access event and the title resolver; at most 55 characters so
     * the subject type `workflow_<subject>` of SearchKit's host event stays within 64). */
    public const SUBJECT_PATTERN = '/^[a-z][a-z0-9_]{0,54}\z/';

    /** SearchKit group key rule (a lower-case letter, then up to 63 of `a-z`, `0-9`, `_`, `-`). */
    public const GROUP_PATTERN = '/^[a-z][a-z0-9_-]{0,63}\z/';

    /** Group keys SearchKit keeps for itself. */
    public const RESERVED_GROUP_PREFIX = 'nowo_search_';

    /** A mapped field name. */
    public const FIELD_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]{0,63}\z/';

    /** A route name or route parameter name. */
    public const ROUTE_PATTERN = '/^[A-Za-z0-9_.:-]{1,128}\z/';

    /** A translation key or domain. */
    public const LABEL_PATTERN = '/^[A-Za-z0-9_.-]{1,128}\z/';

    /** Default group label key, in the bundle's domain. */
    public const DEFAULT_LABEL = 'search.group.workflow_requests';

    /** The largest `narrower_max_values`. */
    public const MAX_NARROWER_VALUES = WorkflowRequestSearchCriteria::MAX_TOTAL_VALUES;

    /** SearchKit's bounds of the candidate window. */
    public const MAX_CANDIDATES = 1000;

    /**
     * @param list<string> $excludedStatuses statuses (place names / enum values) never searched, e.g. draft, cancelled
     * @param array<string, string> $routeParameters fixed parameters of the request route
     * @param array<string, string> $seeAllParameters fixed parameters of the list route
     */
    public function __construct(
        public string $subject,
        public string $subjectClass,
        public string $route,
        public string $gate,
        public ?string $group = null,
        public ?string $titleResolver = null,
        public ?string $narrower = null,
        public string $idField = 'id',
        public ?string $searchTextField = null,
        public ?string $titleField = null,
        public string $statusField = 'status',
        public ?string $tenantField = null,
        public bool $singleTenant = false,
        public ?string $archivedField = null,
        public ?string $orderField = null,
        public array $excludedStatuses = [],
        public string $routeIdParameter = 'id',
        public array $routeParameters = [],
        public ?string $seeAllRoute = null,
        public array $seeAllParameters = [],
        public string $label = self::DEFAULT_LABEL,
        public ?string $labelDomain = null,
        public ?string $statusLabelDomain = null,
        public string $statusLabelPrefix = '',
        public ?string $icon = 'tabler:checklist',
        public bool $sensitive = true,
        public int $maxCandidates = 100,
        public int $minLength = 0,
        public int $priority = 0,
        public bool $acceptWindowSideChannel = false,
        public int $narrowerMaxValues = WorkflowRequestSearchCriteria::DEFAULT_MAX_VALUES,
    ) {
        $this->check(self::SUBJECT_PATTERN, $subject, 'nowo_workflow.search_kit.subjects', 'key');
        $name = 'nowo_workflow.search_kit.subjects.' . $subject;
        if ($subjectClass === '' || $route === '' || $gate === '') {
            throw new InvalidArgumentException(sprintf('%s needs subject_class, route and gate.', $name));
        }
        $groupKey = $this->groupKey();
        $this->check(self::GROUP_PATTERN, $groupKey, $name, 'group');
        if (str_starts_with($groupKey, self::RESERVED_GROUP_PREFIX)) {
            throw new InvalidArgumentException(sprintf('%s: group must not start with "%s" (SearchKit keeps those).', $name, self::RESERVED_GROUP_PREFIX));
        }
        foreach (['id_field' => $idField, 'search_text_field' => $searchTextField, 'title_field' => $titleField, 'status_field' => $statusField, 'tenant_field' => $tenantField, 'archived_field' => $archivedField, 'order_field' => $orderField] as $option => $field) {
            if ($field !== null) {
                $this->check(self::FIELD_PATTERN, $field, $name, $option);
            }
        }
        if ($searchTextField === null && $titleField === null) {
            throw new InvalidArgumentException(sprintf('%s needs search_text_field or title_field (what is searched).', $name));
        }
        if ($titleResolver === null && $titleField === null) {
            throw new InvalidArgumentException(sprintf('%s needs title_resolver or title_field (what is shown).', $name));
        }
        if ($tenantField === null && !$singleTenant) {
            throw new InvalidArgumentException(sprintf('%s needs tenant_field, or single_tenant: true when every request belongs to one tenant.', $name));
        }
        if ($tenantField !== null && $singleTenant) {
            throw new InvalidArgumentException(sprintf('%s sets both tenant_field and single_tenant.', $name));
        }
        if ($sensitive && $narrower === null && !$acceptWindowSideChannel) {
            throw new InvalidArgumentException(sprintf('%s is sensitive: set a narrower that filters what the viewer may not see in SQL, or accept_window_side_channel: true (rows hidden only by the access event take places in the candidate window, so the results can tell they exist).', $name));
        }
        foreach (['route' => $route, 'route_id_parameter' => $routeIdParameter, 'see_all_route' => $seeAllRoute] as $option => $value) {
            if ($value !== null) {
                $this->check(self::ROUTE_PATTERN, $value, $name, $option);
            }
        }
        foreach ([$routeParameters, $seeAllParameters] as $parameters) {
            foreach ($parameters as $parameter => $value) {
                $this->check(self::ROUTE_PATTERN, (string) $parameter, $name, 'route parameter');
            }
        }
        foreach (['label' => $label, 'label_domain' => $labelDomain, 'status_label_domain' => $statusLabelDomain] as $option => $value) {
            if ($value !== null) {
                $this->check(self::LABEL_PATTERN, $value, $name, $option);
            }
        }
        foreach ($excludedStatuses as $status) {
            if ($status === '') {
                throw new InvalidArgumentException(sprintf('%s: excluded_statuses must not hold an empty status.', $name));
            }
        }
        if ($narrowerMaxValues < 1 || $narrowerMaxValues > self::MAX_NARROWER_VALUES) {
            throw new InvalidArgumentException(sprintf('%s: narrower_max_values must be 1 to %d.', $name, self::MAX_NARROWER_VALUES));
        }
        if ($maxCandidates < 1 || $maxCandidates > self::MAX_CANDIDATES || $minLength < 0) {
            throw new InvalidArgumentException(sprintf('%s: max_candidates must be 1 to %d and min_length at least 0.', $name, self::MAX_CANDIDATES));
        }
    }

    /**
     * The options of one processed `nowo_workflow.search_kit.subjects.<subject>` entry.
     *
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException when a required option is missing or a value is invalid
     */
    public static function fromConfig(string $subject, array $config): self
    {
        $missing = [];
        foreach (['subject_class', 'route', 'gate'] as $required) {
            if (!is_string($config[$required] ?? null) || $config[$required] === '') {
                $missing[] = $required;
            }
        }
        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf('nowo_workflow.search_kit.subjects.%s: set %s.', $subject, implode(', ', $missing)));
        }

        return new self(
            subject: $subject,
            subjectClass: self::string($config, 'subject_class'),
            route: self::string($config, 'route'),
            gate: self::string($config, 'gate'),
            group: self::nullableString($config, 'group'),
            titleResolver: self::nullableString($config, 'title_resolver'),
            narrower: self::nullableString($config, 'narrower'),
            idField: self::string($config, 'id_field', 'id'),
            searchTextField: self::nullableString($config, 'search_text_field'),
            titleField: self::nullableString($config, 'title_field'),
            statusField: self::string($config, 'status_field', 'status'),
            tenantField: self::nullableString($config, 'tenant_field'),
            singleTenant: ($config['single_tenant'] ?? false) === true,
            archivedField: self::nullableString($config, 'archived_field'),
            orderField: self::nullableString($config, 'order_field'),
            excludedStatuses: self::strings($config, 'excluded_statuses'),
            routeIdParameter: self::string($config, 'route_id_parameter', 'id'),
            routeParameters: self::map($config, 'route_parameters'),
            seeAllRoute: self::nullableString($config, 'see_all_route'),
            seeAllParameters: self::map($config, 'see_all_parameters'),
            label: self::string($config, 'label', self::DEFAULT_LABEL),
            labelDomain: self::nullableString($config, 'label_domain'),
            statusLabelDomain: self::nullableString($config, 'status_label_domain'),
            statusLabelPrefix: self::string($config, 'status_label_prefix'),
            icon: self::nullableString($config, 'icon'),
            sensitive: !is_bool($config['sensitive'] ?? null) || $config['sensitive'],
            maxCandidates: is_int($config['max_candidates'] ?? null) ? $config['max_candidates'] : 100,
            minLength: is_int($config['min_length'] ?? null) ? $config['min_length'] : 0,
            priority: is_int($config['priority'] ?? null) ? $config['priority'] : 0,
            acceptWindowSideChannel: ($config['accept_window_side_channel'] ?? false) === true,
            narrowerMaxValues: is_int($config['narrower_max_values'] ?? null) ? $config['narrower_max_values'] : WorkflowRequestSearchCriteria::DEFAULT_MAX_VALUES,
        );
    }

    /** The SearchKit group key: `group`, else `workflow_<subject>`. */
    public function groupKey(): string
    {
        return $this->group ?? 'workflow_' . $this->subject;
    }

    /** The field searched as text: `search_text_field`, else `title_field`. */
    public function searchField(): string
    {
        return (string) ($this->searchTextField ?? $this->titleField);
    }

    /** SearchKit's subject type of these rows in its host event: `workflow_<subject>`. */
    public function subjectType(): string
    {
        return 'workflow_' . $this->subject;
    }

    /** The candidate window SearchKit reads (1 to 1000). */
    public function candidates(): int
    {
        return max(1, min($this->maxCandidates, self::MAX_CANDIDATES));
    }

    private function check(string $pattern, string $value, string $name, string $option): void
    {
        if (preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('%s: %s is not valid: "%s".', $name, $option, $value));
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
                $values[$name] = $value;
            }
        }

        return $values;
    }
}
