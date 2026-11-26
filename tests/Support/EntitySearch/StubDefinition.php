<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\EntitySearch;

use Closure;
use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\QueryBuilder as OrmQueryBuilder;
use Nowo\SearchKitBundle\Model\SearchFilter;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Provider\Entity\SearchCandidateContext;

use function is_array;
use function is_scalar;

/**
 * A definition whose hooks a test switches one by one; every optional hook left null runs the base class default.
 */
final class StubDefinition extends EntitySearchDefinition
{
    public string $group = 'stub';

    public int|string|null $tenant = 'tenant-a';

    public bool $feature = true;

    /** @var list<string> */
    public array $fields = ['n.title'];

    public ?string $seeAll = '/stub';

    /** @var (Closure(int|string): (DbalQueryBuilder|OrmQueryBuilder))|null */
    public ?Closure $query = null;

    /** @var (Closure(DbalQueryBuilder|OrmQueryBuilder, string): bool)|null */
    public ?Closure $scope = null;

    /** @var (Closure(DbalQueryBuilder|OrmQueryBuilder, list<string>): bool)|null */
    public ?Closure $fullText = null;

    /** @var (Closure(list<array<string, mixed>|object>): list<array<string, mixed>|object>)|null */
    public ?Closure $visible = null;

    /** @var (Closure(array<string, mixed>|object): SearchItem)|null */
    public ?Closure $item = null;

    /** @var (Closure(SearchCandidateContext, string): void)|null Called by candidateQuery() / filterCandidates() with the hook name */
    public ?Closure $candidates = null;

    /** @var list<string>|null Filter keys (null: the default, none) */
    public ?array $filterKeys = null;

    /** @var (Closure(DbalQueryBuilder|OrmQueryBuilder, list<SearchFilter>): bool)|null */
    public ?Closure $filters = null;

    public ?int $min = null;

    public ?int $max = null;

    public ?bool $sensitive = null;

    public ?string $icon = null;

    public bool $useIcon = false;

    public ?string $idField = null;

    public bool $useIdField = false;

    public bool $useReferenceId = false;

    public bool $canView = true;

    public function __construct(private readonly ?NoteDatabase $database = null)
    {
    }

    public function group(): string
    {
        return $this->group;
    }

    public function label(SearchQuery $query): string
    {
        return 'Stub';
    }

    public function icon(): ?string
    {
        return $this->useIcon ? $this->icon : parent::icon();
    }

    public function tenant(SearchQuery $query): int|string|null
    {
        return $this->tenant;
    }

    public function featureEnabled(int|string $tenant, SearchQuery $query): bool
    {
        return $this->feature;
    }

    public function searchableFields(): array
    {
        return $this->fields;
    }

    public function baseQuery(int|string $tenant, SearchQuery $query): DbalQueryBuilder|OrmQueryBuilder
    {
        if ($this->query instanceof Closure) {
            return ($this->query)($tenant);
        }

        return ($this->database ?? NoteDatabase::get())->connection->createQueryBuilder()
            ->select('n.id', 'n.title')
            ->from(NoteDatabase::TABLE, 'n')
            ->where('n.tenant = :tenant')
            ->setParameter('tenant', (string) $tenant)
            ->orderBy('n.id', 'ASC');
    }

    public function candidateQuery(int|string $tenant, SearchQuery $query, SearchCandidateContext $context): DbalQueryBuilder|OrmQueryBuilder
    {
        if ($this->candidates instanceof Closure) {
            ($this->candidates)($context, 'candidateQuery');
        }

        return parent::candidateQuery($tenant, $query, $context);
    }

    public function applyScope(DbalQueryBuilder|OrmQueryBuilder $queryBuilder, string $scope, int|string $tenant, SearchQuery $query): bool
    {
        return $this->scope instanceof Closure ? ($this->scope)($queryBuilder, $scope) : parent::applyScope($queryBuilder, $scope, $tenant, $query);
    }

    public function supportedFilters(): array
    {
        return $this->filterKeys ?? parent::supportedFilters();
    }

    public function applyFilters(DbalQueryBuilder|OrmQueryBuilder $queryBuilder, array $filters, int|string $tenant, SearchQuery $query): bool
    {
        return $this->filters instanceof Closure ? ($this->filters)($queryBuilder, $filters) : parent::applyFilters($queryBuilder, $filters, $tenant, $query);
    }

    public function applyFullText(DbalQueryBuilder|OrmQueryBuilder $queryBuilder, array $terms, SearchQuery $query): bool
    {
        return $this->fullText instanceof Closure ? ($this->fullText)($queryBuilder, $terms) : parent::applyFullText($queryBuilder, $terms, $query);
    }

    public function canView(array|object $row, int|string $tenant, SearchQuery $query): bool
    {
        return $this->canView || parent::canView($row, $tenant, $query);
    }

    public function filterVisible(array $rows, int|string $tenant, SearchQuery $query): array
    {
        return $this->visible instanceof Closure ? ($this->visible)($rows) : parent::filterVisible($rows, $tenant, $query);
    }

    public function filterCandidates(array $rows, int|string $tenant, SearchQuery $query, SearchCandidateContext $context): array
    {
        if ($this->candidates instanceof Closure) {
            ($this->candidates)($context, 'filterCandidates');
        }

        return parent::filterCandidates($rows, $tenant, $query, $context);
    }

    public function toItem(array|object $row, SearchQuery $query): SearchItem
    {
        if ($this->item instanceof Closure) {
            return ($this->item)($row);
        }
        $title = is_array($row) ? ($row['title'] ?? '') : '';

        return new SearchItem(is_scalar($title) ? (string) $title : '', '/stub/' . $this->subjectId($row));
    }

    public function seeAllUrl(SearchQuery $query): ?string
    {
        return $this->seeAll;
    }

    public function minLength(): int
    {
        return $this->min ?? parent::minLength();
    }

    public function maxCandidates(): int
    {
        return $this->max ?? parent::maxCandidates();
    }

    public function sensitive(): bool
    {
        return $this->sensitive ?? parent::sensitive();
    }

    public function idField(): ?string
    {
        return $this->useIdField ? $this->idField : parent::idField();
    }

    public function referenceId(SearchReference $reference): int|string|null
    {
        if (!$this->useReferenceId) {
            return parent::referenceId($reference);
        }
        $id = $reference->routeParameters['id'] ?? null;

        return is_scalar($id) ? (string) $id : null;
    }
}
