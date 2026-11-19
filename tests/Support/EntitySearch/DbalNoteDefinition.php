<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\EntitySearch;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\QueryBuilder as OrmQueryBuilder;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Search\Query\ScopeParser;

use function array_filter;
use function array_values;
use function is_array;
use function is_scalar;
use function rawurlencode;

/**
 * DBAL definition of the test notes: tenant and archived rows filtered in SQL, hidden rows by the batch check (one
 * pass, no query), `owner:<name>` scopes.
 */
final class DbalNoteDefinition extends EntitySearchDefinition
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Viewer $viewer,
    ) {
    }

    public function group(): string
    {
        return 'notes';
    }

    public function label(SearchQuery $query): string
    {
        return 'Notes';
    }

    public function icon(): string
    {
        return 'tabler:note';
    }

    public function tenant(SearchQuery $query): int|string|null
    {
        return $query->userIdentifier === $this->viewer->userIdentifier ? $this->viewer->tenant : null;
    }

    public function featureEnabled(int|string $tenant, SearchQuery $query): bool
    {
        return $this->viewer->feature;
    }

    public function searchableFields(): array
    {
        return ['n.title', 'n.body'];
    }

    public function baseQuery(int|string $tenant, SearchQuery $query): DbalQueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('n.id', 'n.title', 'n.hidden')
            ->from(NoteDatabase::TABLE, 'n')
            ->where('n.tenant = :tenant')
            ->andWhere('n.archived = :archived')
            ->setParameter('tenant', (string) $tenant)
            ->setParameter('archived', false, ParameterType::BOOLEAN)
            ->orderBy('n.id', 'ASC');
    }

    public function applyScope(DbalQueryBuilder|OrmQueryBuilder $queryBuilder, string $scope, int|string $tenant, SearchQuery $query): bool
    {
        $owner = ScopeParser::value($scope, 'owner');
        if ($owner === null) {
            return false;
        }
        $queryBuilder->andWhere('n.owner = :owner')->setParameter('owner', $owner);

        return true;
    }

    public function filterVisible(array $rows, int|string $tenant, SearchQuery $query): array
    {
        return array_values(array_filter($rows, static fn (array|object $row): bool => is_array($row) && !(bool) ($row['hidden'] ?? true)));
    }

    public function toItem(array|object $row, SearchQuery $query): SearchItem
    {
        $title = (array) $row;
        $title = $title['title'] ?? '';

        return new SearchItem(is_scalar($title) ? (string) $title : '', '/notes/' . $this->subjectId($row));
    }

    public function seeAllUrl(SearchQuery $query): string
    {
        return '/notes?q=' . rawurlencode($query->text);
    }

    public function idField(): string
    {
        return 'n.id';
    }

    public function referenceId(SearchReference $reference): ?string
    {
        $id = $reference->routeParameters['id'] ?? null;

        return $reference->route === 'note_show' && is_scalar($id) ? (string) $id : null;
    }
}
