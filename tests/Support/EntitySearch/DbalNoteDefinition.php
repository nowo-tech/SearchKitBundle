<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\EntitySearch;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\QueryBuilder as OrmQueryBuilder;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Provider\Entity\SearchCandidateContext;
use Nowo\SearchKitBundle\Search\Query\LikePattern;
use Nowo\SearchKitBundle\Search\Query\ScopeParser;
use Nowo\SearchKitBundle\Search\Query\SearchWords;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function ctype_digit;
use function is_array;
use function is_scalar;
use function is_string;
use function rawurlencode;

/**
 * DBAL definition of the test notes (`$maxCandidates` and `$hiddenFirst`, 1.11.1: a small window and hidden rows
 * ordered before visible ones, for the kit's order-independence self-test): tenant and archived rows filtered in SQL, hidden rows by the batch check (one
 * pass, no query), `owner:<name>` scopes. With `$ownerBound` (1.11.0), it scans at most that many owners whose notes
 * match the text — like a bundle that reads at most N boards — and marks the candidates partial when more exist.
 */
final class DbalNoteDefinition extends EntitySearchDefinition
{
    use NoteFilters;

    public function __construct(
        private readonly Connection $connection,
        private readonly Viewer $viewer,
        private readonly ?int $ownerBound = null,
        private readonly bool $marksPartial = true,
        private readonly ?int $maxCandidates = null,
        private readonly bool $hiddenFirst = false,
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

    /**
     * The rows of the tenant, bounded like candidateQuery() (one private builder: baseQuery() stays consistent with
     * it; the flag of a hit bound has nowhere to go here and is dropped).
     */
    public function baseQuery(int|string $tenant, SearchQuery $query): DbalQueryBuilder
    {
        return $this->notes($tenant, $query, new SearchCandidateContext());
    }

    public function candidateQuery(int|string $tenant, SearchQuery $query, SearchCandidateContext $context): DbalQueryBuilder
    {
        return $this->notes($tenant, $query, $context);
    }

    /**
     * The one builder of baseQuery() and candidateQuery(): tenant and archived rows in SQL, then the owner bound
     * (marks `$context` partial when more owners match than it scans).
     */
    private function notes(int|string $tenant, SearchQuery $query, SearchCandidateContext $context): DbalQueryBuilder
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('n.id', 'n.title', 'n.hidden')
            ->from(NoteDatabase::TABLE, 'n')
            ->where('n.tenant = :tenant')
            ->andWhere('n.archived = :archived')
            ->setParameter('tenant', (string) $tenant)
            ->setParameter('archived', false, ParameterType::BOOLEAN);
        if ($this->hiddenFirst) {
            // 1.11.1: the worst order for the kit — every row the viewer may not see before every visible one, like
            // equal timestamps with a random id tiebreak at its unluckiest draw.
            $queryBuilder->orderBy('n.hidden', 'DESC');
        }
        $queryBuilder->addOrderBy('n.id', 'ASC');
        $words = SearchWords::words($query->text);
        if ($this->ownerBound === null || $words === []) {
            return $queryBuilder;
        }
        $owners = $this->connection->createQueryBuilder()
            ->select('DISTINCT o.owner')
            ->from(NoteDatabase::TABLE, 'o')
            ->where('o.tenant = :tenant')
            ->setParameter('tenant', (string) $tenant)
            ->orderBy('o.owner', 'ASC')
            ->setMaxResults($this->ownerBound + 1);
        foreach ($words as $index => $word) {
            $owners->andWhere(LikePattern::condition('o.title', 'w' . $index))->setParameter('w' . $index, LikePattern::contains($word));
        }
        /** @var list<string> $owners */
        $owners = $owners->executeQuery()->fetchFirstColumn();
        if ($this->marksPartial && count($owners) > $this->ownerBound) {
            $context->markPartial();
        }

        return $queryBuilder
            ->andWhere('n.owner IN (:owners)')
            ->setParameter('owners', array_slice($owners, 0, $this->ownerBound), ArrayParameterType::STRING);
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

    public function maxCandidates(): int
    {
        return $this->maxCandidates ?? parent::maxCandidates();
    }

    public function idField(): string
    {
        return 'n.id';
    }

    public function referenceId(SearchReference $reference): ?string
    {
        $id = $reference->routeParameters['id'] ?? null;

        return $reference->route === 'note_show' && is_string($id) && ctype_digit($id) ? $id : null;
    }
}
