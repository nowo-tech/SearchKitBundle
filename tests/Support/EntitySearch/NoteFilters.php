<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\EntitySearch;

use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\QueryBuilder as OrmQueryBuilder;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Search\Query\FilterCondition;

/**
 * The typed filters of the test notes (1.13.0), shared by the DBAL and ORM definitions (alias `n`):
 *
 * - `owner:<name>` / `owner:me` — the owner, resolved in SQL: a name of another tenant meets the tenant condition of
 *   baseQuery() exactly like a name nobody has (same statement, no row);
 * - `body:<text>` — the exact body (a NULL body is kept by `-body:`);
 * - `is:mine` — the viewer's notes; any other `is:` word matches nothing (and `-is:<word>` excludes nothing).
 */
trait NoteFilters
{
    /**
     * With `type` (the engine's), an invalid key and a duplicate, which EntitySearchProvider::supportedFilters() drops.
     */
    public function supportedFilters(): array
    {
        return ['owner', 'body', 'is', 'type', 'Bad Key', 'owner'];
    }

    public function applyFilters(DbalQueryBuilder|OrmQueryBuilder $queryBuilder, array $filters, int|string $tenant, SearchQuery $query): bool
    {
        foreach ($filters as $index => $filter) {
            $parameter = 'note_filter' . $index;
            if ($filter->key === 'is') {
                if ($filter->value === 'mine') {
                    FilterCondition::equals($queryBuilder, 'n.owner', $query->userIdentifier, $filter->negated, $parameter);
                } elseif (!$filter->negated) {
                    FilterCondition::matchNothing($queryBuilder);
                }
                continue;
            }
            $value = $filter->key === 'owner' && $filter->value === 'me' ? $query->userIdentifier : $filter->value;
            FilterCondition::equals($queryBuilder, $filter->key === 'owner' ? 'n.owner' : 'n.body', $value, $filter->negated, $parameter);
        }

        return true;
    }
}
