<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Test\Internal;

use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\QueryBuilder as OrmQueryBuilder;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Provider\Entity\SearchCandidateContext;
use Nowo\SearchKitBundle\Provider\Entity\SearchItemContext;
use Nowo\SearchKitBundle\Test\QueryCounter;

use function array_map;
use function count;

/**
 * @internal the bundle's definition, unchanged, with every prepareItems() and toPreparedItem() call recorded (test
 *           kit only): the rows' ids, the context object and the statements prepareItems() sent; optionally with a
 *           smaller candidate window (1.10.1)
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class RenderRecorder extends EntitySearchDefinition
{
    /** @var list<array{ids: list<string>, context: SearchItemContext, queries: int}> prepareItems() calls, in order */
    public array $prepared = [];

    /** @var list<array{id: string, context: SearchItemContext, after: int}> toPreparedItem() calls; `after`: prepareItems() calls before it */
    public array $rendered = [];

    /**
     * @param EntitySearchDefinition $inner The definition under test
     * @param QueryCounter $counter The test connection's counter
     */
    public function __construct(
        private readonly EntitySearchDefinition $inner,
        private readonly QueryCounter $counter,
        private readonly ?int $window = null,
    ) {
    }

    /**
     * Forwards to the wrapped definition: the group key.
     */
    public function group(): string
    {
        return $this->inner->group();
    }

    /**
     * Forwards to the wrapped definition: the translated group label.
     *
     * @param SearchQuery $query The search
     */
    public function label(SearchQuery $query): string
    {
        return $this->inner->label($query);
    }

    /**
     * Forwards to the wrapped definition: the default UX Icons name, or null.
     */
    public function icon(): ?string
    {
        return $this->inner->icon();
    }

    /**
     * Forwards to the wrapped definition: the subject type given to the host event.
     */
    public function subjectType(): string
    {
        return $this->inner->subjectType();
    }

    /**
     * Forwards to the wrapped definition: the viewer's tenant, or null.
     *
     * @param SearchQuery $query The search
     */
    public function tenant(SearchQuery $query): int|string|null
    {
        return $this->inner->tenant($query);
    }

    /**
     * Forwards to the wrapped definition: whether the feature is on for this tenant.
     *
     * @param int|string $tenant The tenant
     * @param SearchQuery $query The search
     */
    public function featureEnabled(int|string $tenant, SearchQuery $query): bool
    {
        return $this->inner->featureEnabled($tenant, $query);
    }

    /**
     * Forwards to the wrapped definition: the fields searched with LIKE.
     *
     * @return list<string>
     */
    public function searchableFields(): array
    {
        return $this->inner->searchableFields();
    }

    /**
     * Forwards to the wrapped definition: the query builder of the rows the viewer may list.
     *
     * @param int|string $tenant The tenant
     * @param SearchQuery $query The search
     */
    public function baseQuery(int|string $tenant, SearchQuery $query): DbalQueryBuilder|OrmQueryBuilder
    {
        return $this->inner->baseQuery($tenant, $query);
    }

    /**
     * Forwards to the wrapped definition: the query builder of the candidates of this search.
     *
     * @param int|string $tenant The tenant
     * @param SearchQuery $query The search
     * @param SearchCandidateContext $context The candidates of this search
     */
    public function candidateQuery(int|string $tenant, SearchQuery $query, SearchCandidateContext $context): DbalQueryBuilder|OrmQueryBuilder
    {
        return $this->inner->candidateQuery($tenant, $query, $context);
    }

    /**
     * Forwards to the wrapped definition: applies a scope to the query.
     *
     * @param DbalQueryBuilder|OrmQueryBuilder $queryBuilder The query of baseQuery()
     * @param string $scope The scope id
     * @param int|string $tenant The tenant
     * @param SearchQuery $query The search
     */
    public function applyScope(DbalQueryBuilder|OrmQueryBuilder $queryBuilder, string $scope, int|string $tenant, SearchQuery $query): bool
    {
        return $this->inner->applyScope($queryBuilder, $scope, $tenant, $query);
    }

    /**
     * Forwards to the wrapped definition: applies the definition's full-text condition, if any.
     *
     * @param DbalQueryBuilder|OrmQueryBuilder $queryBuilder The query of baseQuery()
     * @param list<string> $terms The full-text terms of the query text
     * @param SearchQuery $query The search
     */
    public function applyFullText(DbalQueryBuilder|OrmQueryBuilder $queryBuilder, array $terms, SearchQuery $query): bool
    {
        return $this->inner->applyFullText($queryBuilder, $terms, $query);
    }

    /**
     * Forwards to the wrapped definition: the per-row access check.
     *
     * @param array<string, mixed>|object $row A candidate
     * @param int|string $tenant The tenant
     * @param SearchQuery $query The search
     */
    public function canView(array|object $row, int|string $tenant, SearchQuery $query): bool
    {
        return $this->inner->canView($row, $tenant, $query);
    }

    /**
     * Forwards to the wrapped definition: the batch access check.
     *
     * @param list<array<string, mixed>|object> $rows The candidates
     * @param int|string $tenant The tenant
     * @param SearchQuery $query The search
     *
     * @return list<array<string, mixed>|object>
     */
    public function filterVisible(array $rows, int|string $tenant, SearchQuery $query): array
    {
        return $this->inner->filterVisible($rows, $tenant, $query);
    }

    /**
     * Forwards to the wrapped definition: the batch access check with the candidates' context.
     *
     * @param list<array<string, mixed>|object> $rows The candidates
     * @param int|string $tenant The tenant
     * @param SearchQuery $query The search
     * @param SearchCandidateContext $context The candidates of this search
     *
     * @return list<array<string, mixed>|object>
     */
    public function filterCandidates(array $rows, int|string $tenant, SearchQuery $query, SearchCandidateContext $context): array
    {
        return $this->inner->filterCandidates($rows, $tenant, $query, $context);
    }

    /**
     * Forwards to the wrapped definition: the id of a row.
     *
     * @param array<string, mixed>|object $row A row
     */
    public function subjectId(array|object $row): int|string
    {
        return $this->inner->subjectId($row);
    }

    /**
     * Forwards to the wrapped definition: the palette row of a visible record.
     *
     * @param array<string, mixed>|object $row A visible row
     * @param SearchQuery $query The search
     */
    public function toItem(array|object $row, SearchQuery $query): SearchItem
    {
        return $this->inner->toItem($row, $query);
    }

    /**
     * Runs the wrapped definition's prepareItems() and records the call: the rows' ids, the context and the statements it sent.
     *
     * @param list<array<string, mixed>|object> $shownRows The rows that will be shown, in order
     * @param SearchQuery $query The search
     * @param SearchItemContext $context Display data of this render
     */
    public function prepareItems(array $shownRows, SearchQuery $query, SearchItemContext $context): void
    {
        $before = $this->counter->count();
        $this->inner->prepareItems($shownRows, $query, $context);
        $this->prepared[] = [
            'ids'     => array_map(fn (array|object $row): string => (string) $this->inner->subjectId($row), $shownRows),
            'context' => $context,
            'queries' => $this->counter->count() - $before,
        ];
    }

    /**
     * Records the call, then returns the wrapped definition's palette row built with the display data prepareItems() stored.
     *
     * @param array<string, mixed>|object $row A shown row
     * @param SearchQuery $query The search
     * @param SearchItemContext $context Display data of this render
     */
    public function toPreparedItem(array|object $row, SearchQuery $query, SearchItemContext $context): SearchItem
    {
        $this->rendered[] = ['id' => (string) $this->inner->subjectId($row), 'context' => $context, 'after' => count($this->prepared)];

        return $this->inner->toPreparedItem($row, $query, $context);
    }

    /**
     * Forwards to the wrapped definition: the "see all" link of the group, or null.
     *
     * @param SearchQuery $query The search
     */
    public function seeAllUrl(SearchQuery $query): ?string
    {
        return $this->inner->seeAllUrl($query);
    }

    /**
     * Forwards to the wrapped definition: the shortest query text this group searches.
     */
    public function minLength(): int
    {
        return $this->inner->minLength();
    }

    /**
     * The smaller candidate window given to the constructor, or the wrapped definition's own.
     */
    public function maxCandidates(): int
    {
        return $this->window ?? $this->inner->maxCandidates();
    }

    /**
     * Forwards to the wrapped definition: whether row titles are sensitive.
     */
    public function sensitive(): bool
    {
        return $this->inner->sensitive();
    }

    /**
     * Forwards to the wrapped definition: the id field of baseQuery(), or null.
     */
    public function idField(): ?string
    {
        return $this->inner->idField();
    }

    /**
     * Forwards to the wrapped definition: the record id of a stored recent, or null.
     *
     * @param SearchReference $reference The stored reference (client input)
     */
    public function referenceId(SearchReference $reference): int|string|null
    {
        return $this->inner->referenceId($reference);
    }

    /**
     * Forgets the calls recorded so far.
     */
    public function reset(): void
    {
        $this->prepared = [];
        $this->rendered = [];
    }
}
