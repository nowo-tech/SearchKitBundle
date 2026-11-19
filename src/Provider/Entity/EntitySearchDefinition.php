<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Provider\Entity;

use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\QueryBuilder as OrmQueryBuilder;
use LogicException;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;

use function array_filter;
use function array_key_exists;
use function array_values;
use function is_array;
use function is_int;
use function is_object;
use function is_string;
use function method_exists;

/**
 * What a bundle writes to put its records in the SearchKit palette and results page (1.9.0): only the hooks below.
 * The flow — tenant, feature gate, min length, scope, escaped LIKE or full text, bounded window, per-row access, the
 * shared host event, the visible total, the "see all" link, `null` on any refusal — is EntitySearchProvider's and is
 * the same for every bundle.
 *
 * Register the definition as a service: with autoconfiguration it gets the EntitySearchDefinition::TAG tag (optional
 * attribute `priority`, the position of its group among the providers, default 0); without it, add the tag yourself.
 * SearchKit then registers one EntitySearchProvider for it. Wire the definition only when SearchKit is installed.
 *
 * Every hook is called per search and must keep nothing between calls (FrankenPHP worker mode, kernel reset off):
 * resolve the tenant and the viewer from request-scoped services each time.
 *
 * A row is what baseQuery() returns: an associative array with DBAL, an entity (or array) with the ORM.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
abstract class EntitySearchDefinition
{
    /** Tag of definition services (autoconfigured); attribute `priority` (int, default 0). */
    public const string TAG = 'nowo_search_kit.entity_definition';

    /** Default number of candidates read and checked per search. */
    public const int DEFAULT_MAX_CANDIDATES = 100;

    /**
     * Group key (`[a-z0-9_-]`, 1 to 64 characters, e.g. `tasks`): the palette section, the `type:` filter value, the
     * `nowo_search_kit.groups.<key>` settings and the default row kind.
     */
    abstract public function group(): string;

    /**
     * Translated group label.
     *
     * @param SearchQuery $query The search (its locale)
     */
    abstract public function label(SearchQuery $query): string;

    /**
     * UX Icons name of a row whose toItem() sets none, or null.
     */
    public function icon(): ?string
    {
        return null;
    }

    /**
     * Subject type given to the host event (SearchSubject::TYPE_PATTERN). Default: the group key.
     */
    public function subjectType(): string
    {
        return $this->group();
    }

    /**
     * The tenant of the viewer, resolved for this call; null means no results.
     *
     * @param SearchQuery $query The search (its user identifier is the viewer)
     *
     * @return int|string|null The tenant id, or null
     */
    abstract public function tenant(SearchQuery $query): int|string|null;

    /**
     * Whether the bundle's feature (and its `view` permission, when it is per tenant) is on for this tenant.
     *
     * @param int|string $tenant The tenant
     * @param SearchQuery $query The search
     */
    abstract public function featureEnabled(int|string $tenant, SearchQuery $query): bool;

    /**
     * Fields searched with LIKE: `column` or `alias.column` of baseQuery() (any word of the query must occur in one
     * of them). Never secret values, notes or bodies of sensitive records.
     *
     * @return list<string>
     */
    abstract public function searchableFields(): array;

    /**
     * A new query builder of the rows the viewer may list in this tenant: SELECT, FROM, the tenant condition, and the
     * SQL filters of visibility, archived and deleted rows, ordered deterministically (e.g. updated_at DESC, id DESC).
     * SearchKit adds the search condition and the limit; set neither, nor bind parameters named `nowo_search_*`.
     * With the ORM, do not fetch-join collections (the limit applies to SQL rows).
     *
     * @param int|string $tenant The tenant
     * @param SearchQuery $query The search
     */
    abstract public function baseQuery(int|string $tenant, SearchQuery $query): DbalQueryBuilder|OrmQueryBuilder;

    /**
     * Applies a scope (client input whose shape SearchKit already checked: ScopeParser helps reading it). Restrict
     * the query to the scoped object **in SQL**, checking it belongs to the tenant, and return true; return false for
     * a scope you do not handle — the search then returns null, like a scope with no match. Default: no scopes.
     *
     * @param DbalQueryBuilder|OrmQueryBuilder $queryBuilder The query of baseQuery()
     * @param string $scope The scope id
     * @param int|string $tenant The tenant
     * @param SearchQuery $query The search
     */
    public function applyScope(DbalQueryBuilder|OrmQueryBuilder $queryBuilder, string $scope, int|string $tenant, SearchQuery $query): bool
    {
        return false;
    }

    /**
     * Full-text hook: add your own match condition from the terms (letters and digits only, never operators) and
     * return true; return false to use the escaped LIKE over searchableFields() (default, and for an empty list).
     *
     * @param DbalQueryBuilder|OrmQueryBuilder $queryBuilder The query of baseQuery()
     * @param list<string> $terms SearchWords::fullTextTerms() of the query text
     * @param SearchQuery $query The search
     */
    public function applyFullText(DbalQueryBuilder|OrmQueryBuilder $queryBuilder, array $terms, SearchQuery $query): bool
    {
        return false;
    }

    /**
     * Per-row access check through the bundle's own checker or access event. Default: deny — override this, or
     * filterVisible() to check a whole page at once (no N+1).
     *
     * @param array<string, mixed>|object $row A candidate
     * @param int|string $tenant The tenant
     * @param SearchQuery $query The search
     */
    public function canView(array|object $row, int|string $tenant, SearchQuery $query): bool
    {
        return false;
    }

    /**
     * Batch access check: the candidates the viewer may see, in order. Default: canView() per row. SearchKit keeps
     * only rows that were candidates, whatever this returns.
     *
     * @param list<array<string, mixed>|object> $rows The candidates
     * @param int|string $tenant The tenant
     * @param SearchQuery $query The search
     *
     * @return list<array<string, mixed>|object>
     */
    public function filterVisible(array $rows, int|string $tenant, SearchQuery $query): array
    {
        return array_values(array_filter($rows, fn (array|object $row): bool => $this->canView($row, $tenant, $query)));
    }

    /**
     * The id of a row (host event subject, deduplication). Default: `$row['id']` or `$row->getId()`.
     *
     * @param array<string, mixed>|object $row A row
     *
     * @throws LogicException When the row has no readable id: override this hook
     */
    public function subjectId(array|object $row): int|string
    {
        $id = null;
        if (is_array($row) && array_key_exists('id', $row)) {
            $id = $row['id'];
        } elseif (is_object($row) && method_exists($row, 'getId')) {
            $id = $row->getId();
        }
        if (is_int($id) || (is_string($id) && $id !== '')) {
            return $id;
        }

        throw new LogicException('EntitySearchDefinition::subjectId() cannot read the id of this row: override it.');
    }

    /**
     * The palette row of a visible record: title, URL (generated by the router), a short subtitle. SearchKit escapes
     * what it shows; put no secret value, note or body of a sensitive record in it.
     *
     * @param array<string, mixed>|object $row A visible row
     * @param SearchQuery $query The search
     */
    abstract public function toItem(array|object $row, SearchQuery $query): SearchItem;

    /**
     * The "see all" link of the group (the bundle's list page with the query; SeeAllLink::build() helps), or null.
     *
     * @param SearchQuery $query The search
     */
    abstract public function seeAllUrl(SearchQuery $query): ?string;

    /**
     * Shortest query text this group searches (SearchKit's `min_length` applies first). Default: 0.
     */
    public function minLength(): int
    {
        return 0;
    }

    /**
     * Candidates read and checked per search (1 to SearchWindow::MAX_CANDIDATES): bounds the access checks, the
     * visible total and the deepest results page.
     */
    public function maxCandidates(): int
    {
        return self::DEFAULT_MAX_CANDIDATES;
    }

    /**
     * Whether row titles are sensitive (message excerpts, private names): the group is then off while SearchKit
     * keeps recents in the browser without revalidation (`recent.enabled: true`, `recent.revalidate: false`).
     */
    public function sensitive(): bool
    {
        return false;
    }

    /**
     * The id field of baseQuery() (`alias.id`), for recents revalidation; null: recents of this group are not
     * revalidated (dropped when `recent.revalidate` is on).
     */
    public function idField(): ?string
    {
        return null;
    }

    /**
     * The record id of a stored recent (from its route parameters), or null when the reference is not one of this
     * group's rows. The row is then read again through baseQuery() and every check, and kept only when toItem() gives
     * the same URL.
     *
     * @param SearchReference $reference The stored reference (client input)
     */
    public function referenceId(SearchReference $reference): int|string|null
    {
        return null;
    }
}
