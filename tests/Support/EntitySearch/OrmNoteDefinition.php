<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\EntitySearch;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder as OrmQueryBuilder;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;

/**
 * ORM definition of the test notes (entities), with the per-row canView() check.
 */
final class OrmNoteDefinition extends EntitySearchDefinition
{
    use NoteFilters;

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly Viewer $viewer,
    ) {
    }

    public function group(): string
    {
        return 'orm_notes';
    }

    public function label(SearchQuery $query): string
    {
        return 'Notes (ORM)';
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

    public function baseQuery(int|string $tenant, SearchQuery $query): OrmQueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('n')
            ->from(Note::class, 'n')
            ->where('n.tenant = :tenant')
            ->andWhere('n.archived = false')
            ->setParameter('tenant', (string) $tenant)
            ->orderBy('n.id', 'ASC');
    }

    public function canView(array|object $row, int|string $tenant, SearchQuery $query): bool
    {
        return $row instanceof Note && !$row->isHidden();
    }

    public function toItem(array|object $row, SearchQuery $query): SearchItem
    {
        return new SearchItem($row instanceof Note ? $row->getTitle() : '', '/orm-notes/' . $this->subjectId($row), 'note', 'tabler:file-text');
    }

    public function seeAllUrl(SearchQuery $query): ?string
    {
        return null;
    }
}
