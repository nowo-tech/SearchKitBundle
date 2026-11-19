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
 * resolve the tenant and the viewer from request-scoped services each time, and put display data loaded for the
 * shown rows in the SearchItemContext of prepareItems(), never in a property.
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
     * Group key (EntitySearchProvider::GROUP_PATTERN: a lower-case letter, then up to 63 of `a-z`, `0-9`, `_`, `-`; e.g. `tasks`): the palette section, the `type:` filter value, the
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
     * The query SearchKit runs (1.11.0): the same contract as baseQuery(), with the SearchCandidateContext of this
     * search. Default: baseQuery(). Override it when the definition bounds its own candidates — e.g. it reads at most
     * N boards (projects, folders) of the tenant to build the query and more exist — and call `$context->markPartial()`
     * when the bound is hit: the total is then reported as inexact ("N+") instead of claiming an exact count of a
     * partial scan. SearchKit calls it once per search page and once per resolved recent (with a new context each
     * time), and never baseQuery() directly, so keep baseQuery() consistent with it (e.g. both build on one private
     * method). Keep the flag in `$context`, never in a property: the definition is a shared service. A recent is
     * resolved with an empty query text: a text-dependent bound then does not apply, a bound that leaves the row out
     * drops the recent (both fail closed). A decorator of another definition must forward this hook and
     * filterCandidates(): the defaults call the decorator's own baseQuery() / filterVisible(), skipping the inner
     * definition's bound.
     *
     * @param int|string $tenant The tenant
     * @param SearchQuery $query The search
     * @param SearchCandidateContext $context The candidates of this search (markPartial())
     */
    public function candidateQuery(int|string $tenant, SearchQuery $query, SearchCandidateContext $context): DbalQueryBuilder|OrmQueryBuilder
    {
        return $this->baseQuery($tenant, $query);
    }

    /**
     * Applies a scope (client input whose shape SearchKit already checked: ScopeParser helps reading it). Restrict
     * the query to the scoped object **in SQL**, checking it belongs to the tenant, and return true; return false for
     * a scope you do not handle — the search then returns null, like a scope with no match. Bind no parameter named
     * `nowo_search_*` (refused with a LogicException). Default: no scopes.
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
     * return true; return false to use the escaped LIKE over searchableFields() (default, and for an empty list). Bind
     * no parameter named `nowo_search_*` (refused with a LogicException).
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
     * The batch access check SearchKit runs (1.11.0): the same contract as filterVisible(), with the
     * SearchCandidateContext of this search — the one candidateQuery() got. Default: filterVisible(). Override it when
     * the check itself is bounded (e.g. it resolves at most N memberships) and call `$context->markPartial()` when the
     * bound is hit. Only rows that were candidates are kept, whatever this returns.
     *
     * @param list<array<string, mixed>|object> $rows The candidates
     * @param int|string $tenant The tenant
     * @param SearchQuery $query The search
     * @param SearchCandidateContext $context The candidates of this search (markPartial())
     *
     * @return list<array<string, mixed>|object>
     */
    public function filterCandidates(array $rows, int|string $tenant, SearchQuery $query, SearchCandidateContext $context): array
    {
        return $this->filterVisible($rows, $tenant, $query);
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
     * Override this, or toPreparedItem() when the row needs data loaded by prepareItems() (1.10.0: no longer
     * abstract; the default throws, so a definition must override one of the two).
     *
     * @param array<string, mixed>|object $row A visible row
     * @param SearchQuery $query The search
     *
     * @throws LogicException When neither toItem() nor toPreparedItem() is overridden
     */
    public function toItem(array|object $row, SearchQuery $query): SearchItem
    {
        throw new LogicException('An EntitySearchDefinition must override toItem() or toPreparedItem().');
    }

    /**
     * Batch hook before the rows are rendered (1.10.0): called **once** per search page with exactly the rows the
     * provider returns — after filterVisible(), after the host's SearchKitEvents::ITEM_VISIBILITY event and after the
     * page cut — and once per resolved recent with that one row, right before toPreparedItem() runs for them. Never
     * called when the provider returns no row, nor for a group the viewer's `type:` filter excludes. With
     * `access.filter_all_rows`, SearchKit's engine checks every returned row's URL afterwards and may still drop some
     * of them. Use it to load display data for those ids only (e.g. titles from a host resolver, in one query: no N+1,
     * and nothing is loaded for a row the bundle's check or the host event hides) and store it in `$context`, which
     * SearchKit then passes to toPreparedItem().
     *
     * Read-only: no persist, flush, clear or transaction, no write of any kind (REQ-RUNTIME-003). Keep what you load
     * in `$context`, never in a property of the definition: the context is new for each call and dropped afterwards,
     * the definition is a shared service (FrankenPHP worker mode, kernel reset off). Its queries count towards the
     * search's query budget. Default: nothing.
     *
     * @param list<array<string, mixed>|object> $shownRows The rows that will be shown, in order (never empty)
     * @param SearchQuery $query The search
     * @param SearchItemContext $context Display data of this render, for toPreparedItem()
     */
    public function prepareItems(array $shownRows, SearchQuery $query, SearchItemContext $context): void
    {
    }

    /**
     * The palette row of a visible record, with the display data prepareItems() stored for this render (1.10.0).
     * Same rules as toItem(). Default: toItem().
     *
     * @param array<string, mixed>|object $row A shown row (one of prepareItems()' rows)
     * @param SearchQuery $query The search
     * @param SearchItemContext $context What prepareItems() stored for this render
     */
    public function toPreparedItem(array|object $row, SearchQuery $query, SearchItemContext $context): SearchItem
    {
        return $this->toItem($row, $query);
    }

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
     * visible total (a lower bound once the SQL fetch fills the window, 1.10.1) and the deepest results page. Rows
     * hidden only in PHP (filterVisible(), a host deny) still take places in the window and can push visible rows out
     * of it: filter them in baseQuery() wherever possible (sensitive groups must).
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
     * group's rows. The reference is client input: check the route and the **shape** of the id (e.g. `ctype_digit()`
     * for an integer key, a UUID pattern) and return null for anything else. The row is then read again through
     * baseQuery() and every check, and kept only when toItem() gives the same URL and kind.
     *
     * @param SearchReference $reference The stored reference (client input)
     */
    public function referenceId(SearchReference $reference): int|string|null
    {
        return null;
    }
}
