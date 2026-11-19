<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Test\Internal;

use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\QueryBuilder as OrmQueryBuilder;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Provider\Entity\SearchItemContext;
use Nowo\SearchKitBundle\Test\QueryCounter;

use function array_map;
use function count;

/**
 * @internal the bundle's definition, unchanged, with every prepareItems() and toPreparedItem() call recorded (test
 *           kit only): the rows' ids, the context object and the statements prepareItems() sent
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
    ) {
    }

    public function group(): string
    {
        return $this->inner->group();
    }

    public function label(SearchQuery $query): string
    {
        return $this->inner->label($query);
    }

    public function icon(): ?string
    {
        return $this->inner->icon();
    }

    public function subjectType(): string
    {
        return $this->inner->subjectType();
    }

    public function tenant(SearchQuery $query): int|string|null
    {
        return $this->inner->tenant($query);
    }

    public function featureEnabled(int|string $tenant, SearchQuery $query): bool
    {
        return $this->inner->featureEnabled($tenant, $query);
    }

    public function searchableFields(): array
    {
        return $this->inner->searchableFields();
    }

    public function baseQuery(int|string $tenant, SearchQuery $query): DbalQueryBuilder|OrmQueryBuilder
    {
        return $this->inner->baseQuery($tenant, $query);
    }

    public function applyScope(DbalQueryBuilder|OrmQueryBuilder $queryBuilder, string $scope, int|string $tenant, SearchQuery $query): bool
    {
        return $this->inner->applyScope($queryBuilder, $scope, $tenant, $query);
    }

    public function applyFullText(DbalQueryBuilder|OrmQueryBuilder $queryBuilder, array $terms, SearchQuery $query): bool
    {
        return $this->inner->applyFullText($queryBuilder, $terms, $query);
    }

    public function canView(array|object $row, int|string $tenant, SearchQuery $query): bool
    {
        return $this->inner->canView($row, $tenant, $query);
    }

    public function filterVisible(array $rows, int|string $tenant, SearchQuery $query): array
    {
        return $this->inner->filterVisible($rows, $tenant, $query);
    }

    public function subjectId(array|object $row): int|string
    {
        return $this->inner->subjectId($row);
    }

    public function toItem(array|object $row, SearchQuery $query): SearchItem
    {
        return $this->inner->toItem($row, $query);
    }

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

    public function toPreparedItem(array|object $row, SearchQuery $query, SearchItemContext $context): SearchItem
    {
        $this->rendered[] = ['id' => (string) $this->inner->subjectId($row), 'context' => $context, 'after' => count($this->prepared)];

        return $this->inner->toPreparedItem($row, $query, $context);
    }

    public function seeAllUrl(SearchQuery $query): ?string
    {
        return $this->inner->seeAllUrl($query);
    }

    public function minLength(): int
    {
        return $this->inner->minLength();
    }

    public function maxCandidates(): int
    {
        return $this->inner->maxCandidates();
    }

    public function sensitive(): bool
    {
        return $this->inner->sensitive();
    }

    public function idField(): ?string
    {
        return $this->inner->idField();
    }

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
