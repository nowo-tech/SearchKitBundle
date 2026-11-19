<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\EntitySearch;

use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Search\Query\SeeAllLink;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function ctype_digit;
use function is_scalar;
use function is_string;

/**
 * The definition a bundle would register (autoconfigured service): alice searches tenant "kernel", bob has none.
 */
final class KernelNoteDefinition extends EntitySearchDefinition
{
    public const string TENANT = 'kernel';

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function group(): string
    {
        return 'kernel_notes';
    }

    public function label(SearchQuery $query): string
    {
        return $this->translator->trans('search.group.pages', [], 'NowoSearchKitBundle', $query->locale) . ' (notes)';
    }

    public function tenant(SearchQuery $query): ?string
    {
        return $query->userIdentifier === 'alice' ? self::TENANT : null;
    }

    public function featureEnabled(int|string $tenant, SearchQuery $query): bool
    {
        return true;
    }

    public function searchableFields(): array
    {
        return ['n.title'];
    }

    public function baseQuery(int|string $tenant, SearchQuery $query): DbalQueryBuilder
    {
        return NoteDatabase::get()->connection->createQueryBuilder()
            ->select('n.id', 'n.title')
            ->from(NoteDatabase::TABLE, 'n')
            ->where('n.tenant = :tenant')
            ->setParameter('tenant', (string) $tenant)
            ->orderBy('n.id', 'ASC');
    }

    public function canView(array|object $row, int|string $tenant, SearchQuery $query): bool
    {
        return true;
    }

    public function toItem(array|object $row, SearchQuery $query): SearchItem
    {
        $title = (array) $row;
        $title = $title['title'] ?? '';

        return new SearchItem(is_scalar($title) ? (string) $title : '', $this->urls->generate('test_note', ['id' => $this->subjectId($row)]));
    }

    public function seeAllUrl(SearchQuery $query): ?string
    {
        return SeeAllLink::build($this->urls, 'test_page', [], $query);
    }

    public function idField(): string
    {
        return 'n.id';
    }

    public function referenceId(SearchReference $reference): ?string
    {
        $id = $reference->routeParameters['id'] ?? null;

        return $reference->route === 'test_note' && is_string($id) && ctype_digit($id) ? $id : null;
    }
}
