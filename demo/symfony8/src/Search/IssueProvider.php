<?php

declare(strict_types=1);

namespace App\Search;

use DateTimeImmutable;
use Nowo\SearchKitBundle\Contract\FilterableSearchProviderInterface;
use Nowo\SearchKitBundle\Contract\IdentifierAwareSearchProviderInterface;
use Nowo\SearchKitBundle\Contract\PaginatedSearchProviderInterface;
use Nowo\SearchKitBundle\Contract\SearchFilterValuesProviderInterface;
use Nowo\SearchKitBundle\Contract\SearchReferenceResolverInterface;
use Nowo\SearchKitBundle\Model\SearchFilterValue;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Issues" group (spec 240 reference provider): visibility first (only issues of projects the viewer may open), then
 * the typed filters `is:open|closed` and `project:<id>` resolved for the viewer, then matching; exact totals, pages for
 * the results page, and recents resolution. An unknown and a hidden project filter answer the same: nothing.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[AsTaggedItem(priority: 40)]
final readonly class IssueProvider implements FilterableSearchProviderInterface, PaginatedSearchProviderInterface, IdentifierAwareSearchProviderInterface, SearchReferenceResolverInterface, SearchFilterValuesProviderInterface
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
    ) {
    }

    public function supportedFilters(): array
    {
        return ['is', 'project'];
    }

    public function groupKey(): string
    {
        return 'issues';
    }

    public function search(SearchQuery $query): ?SearchGroup
    {
        $rows = $this->visible($query);

        return $rows === [] ? null : new SearchGroup('issues', $this->translator->trans('demo.group.issues', [], 'messages', $query->locale), \array_slice($rows, 0, $query->limit), \count($rows), null, null, true);
    }

    public function searchPage(SearchQuery $query, int $page, int $perPage): ?SearchGroup
    {
        $rows = \array_slice($this->visible($query), ($page - 1) * $perPage, $perPage);

        return $rows === [] ? null : new SearchGroup('issues', $this->translator->trans('demo.group.issues', [], 'messages', $query->locale), $rows);
    }

    public function resolve(SearchReference $reference, string $userIdentifier, ?string $locale): ?SearchItem
    {
        $id = (int) ($reference->routeParameters['id'] ?? 0);
        if ($reference->route !== 'demo_issue' || !DemoData::canSeeIssue($userIdentifier, $id)) {
            return null;
        }

        return $this->item($id, DemoData::ISSUES[$id], $locale ?? 'en', null);
    }

    public function supportsKey(string $key): bool
    {
        return \in_array($key, ['is', 'project'], true);
    }

    public function values(string $key, string $prefix, string $userIdentifier, ?string $scope, int $limit): array
    {
        $values = $key === 'is'
            ? [new SearchFilterValue('open'), new SearchFilterValue('closed')]
            : array_map(static fn (string $id, array $p): SearchFilterValue => new SearchFilterValue($id, $p[0]), array_keys(DemoData::projectsFor($userIdentifier)), DemoData::projectsFor($userIdentifier));

        return \array_slice(array_values(array_filter($values, static fn (SearchFilterValue $v): bool => $prefix === '' || str_starts_with($v->value, strtolower($prefix)))), 0, $limit);
    }

    /**
     * @return list<SearchItem>
     */
    private function visible(SearchQuery $query): array
    {
        $rows = [];
        foreach (DemoData::ISSUES as $id => $issue) {
            if (!DemoData::canSeeIssue($query->userIdentifier, $id) || ($query->scope !== null && $query->scope !== $issue[1])) {
                continue;
            }
            foreach ($query->filters as $filter) {
                $match = match ($filter->key) {
                    'is'      => ($filter->value === 'open' && $issue[2]) || ($filter->value === 'closed' && !$issue[2]),
                    'project' => $filter->value === $issue[1],
                    default   => true,
                };
                if ($match === $filter->negated) {
                    continue 2;
                }
            }
            $score = $query->text === '' ? 0.5 : $query->score($issue[0]);
            if ($score !== null) {
                $rows[] = $this->item($id, $issue, $query->locale ?? 'en', $score);
            }
        }

        return $rows;
    }

    /**
     * @param array{string, string, bool, string} $issue
     */
    private function item(int $id, array $issue, string $locale, ?float $score): SearchItem
    {
        return new SearchItem($issue[0], $this->urls->generate('demo_issue', ['_locale' => $locale, 'id' => $id]), DemoData::PROJECTS[$issue[1]][0], 'tabler:point', null, 'issues', $score, new DateTimeImmutable($issue[3]));
    }
}
