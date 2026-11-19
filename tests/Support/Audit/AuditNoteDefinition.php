<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Audit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\NoteDatabase;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\Viewer;

use function is_array;

/** Audit: a configurable, deliberately breakable note definition. */
final class AuditNoteDefinition extends EntitySearchDefinition
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Viewer $viewer,
        public bool $tenantInSql = true,
        public bool $checkHidden = true,
        public int $candidates = 100,
        public bool $bindReserved = false,
        public bool $sensitive = false,
        public bool $hiddenInSql = false,
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
        return ['n.title'];
    }

    public function maxCandidates(): int
    {
        return $this->candidates;
    }

    public function sensitive(): bool
    {
        return $this->sensitive;
    }

    public function baseQuery(int|string $tenant, SearchQuery $query): DbalQueryBuilder
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('n.id', 'n.title', 'n.hidden', 'n.tenant')
            ->from(NoteDatabase::TABLE, 'n')
            ->andWhere('n.archived = :archived')
            ->setParameter('archived', false, ParameterType::BOOLEAN)
            ->orderBy('n.id', 'ASC');
        if ($this->tenantInSql) {
            $qb->andWhere('n.tenant = :tenant')->setParameter('tenant', (string) $tenant);
        }
        if ($this->hiddenInSql) {
            $qb->andWhere('n.hidden = :hidden')->setParameter('hidden', false, ParameterType::BOOLEAN);
        }
        if ($this->bindReserved) {
            // a host filter that happens to use a reserved name
            $qb->andWhere('n.owner = :nowo_search_w0')->setParameter('nowo_search_w0', 'alice');
        }

        return $qb;
    }

    public function filterVisible(array $rows, int|string $tenant, SearchQuery $query): array
    {
        if (!$this->checkHidden) {
            return $rows; // BROKEN: no per-row access check
        }

        return array_values(array_filter($rows, static fn (array|object $row): bool => is_array($row) && !(bool) ($row['hidden'] ?? true)));
    }

    public function toItem(array|object $row, SearchQuery $query): SearchItem
    {
        $row = (array) $row;

        return new SearchItem((string) $row['title'], '/notes/' . $row['id']);
    }

    public function seeAllUrl(SearchQuery $query): ?string
    {
        return null;
    }
}
