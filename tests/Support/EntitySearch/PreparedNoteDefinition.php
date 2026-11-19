<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\EntitySearch;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Provider\Entity\SearchItemContext;

use function array_filter;
use function array_map;
use function array_values;
use function ctype_digit;
use function is_array;
use function is_scalar;
use function is_string;

/**
 * 1.10.0: a DBAL definition of records with no archived state whose titles are not in the search query: prepareItems()
 * loads them for the shown ids in one statement and toPreparedItem() reads them from the context. `$perRow` and
 * `$memoise` break it on purpose (N+1, titles memoised on the instance) for the kit's own tests.
 */
final class PreparedNoteDefinition extends EntitySearchDefinition
{
    public const string TITLES = 'titles';

    /** @var array<string, string>|null Broken on purpose with `$memoise`: titles kept on the instance */
    private ?array $memo = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly Viewer $viewer,
        private readonly bool $perRow = false,
        private readonly bool $memoise = false,
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

    public function baseQuery(int|string $tenant, SearchQuery $query): DbalQueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('n.id', 'n.hidden')
            ->from(NoteDatabase::TABLE, 'n')
            ->where('n.tenant = :tenant')
            ->setParameter('tenant', (string) $tenant)
            ->orderBy('n.id', 'ASC');
    }

    public function filterVisible(array $rows, int|string $tenant, SearchQuery $query): array
    {
        return array_values(array_filter($rows, static fn (array|object $row): bool => is_array($row) && !(bool) ($row['hidden'] ?? true)));
    }

    /**
     * One statement for every shown row (or, broken on purpose with `$perRow`, one per row: N+1).
     */
    public function prepareItems(array $shownRows, SearchQuery $query, SearchItemContext $context): void
    {
        if ($this->memoise && $this->memo !== null) {
            $context->set(self::TITLES, $this->memo);

            return;
        }
        $ids    = array_map(fn (array|object $row): int => (int) $this->subjectId($row), $shownRows);
        $titles = [];
        foreach ($this->perRow ? array_map(static fn (int $id): array => [$id], $ids) : [$ids] as $batch) {
            $rows = $this->connection->createQueryBuilder()
                ->select('n.id', 'n.title')
                ->from(NoteDatabase::TABLE, 'n')
                ->where('n.id IN (:ids)')
                ->setParameter('ids', $batch, ArrayParameterType::INTEGER)
                ->executeQuery()
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                if (is_scalar($row['id']) && is_scalar($row['title'])) {
                    $titles[(string) $row['id']] = (string) $row['title'];
                }
            }
        }
        $context->set(self::TITLES, $titles);
        if ($this->memoise) {
            $this->memo = $titles;
        }
    }

    public function toPreparedItem(array|object $row, SearchQuery $query, SearchItemContext $context): SearchItem
    {
        $id     = (string) $this->subjectId($row);
        $titles = $context->get(self::TITLES, []);

        return new SearchItem(is_array($titles) && is_string($titles[$id] ?? null) ? $titles[$id] : '', '/notes/' . $id);
    }

    public function seeAllUrl(SearchQuery $query): ?string
    {
        return null;
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
