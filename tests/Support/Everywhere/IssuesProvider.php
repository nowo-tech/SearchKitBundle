<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Everywhere;

use DateTimeImmutable;
use Nowo\SearchKitBundle\Contract\FilterableSearchProviderInterface;
use Nowo\SearchKitBundle\Contract\IdentifierAwareSearchProviderInterface;
use Nowo\SearchKitBundle\Contract\PaginatedSearchProviderInterface;
use Nowo\SearchKitBundle\Contract\SearchReferenceResolverInterface;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;

use function array_slice;
use function count;
use function in_array;

/**
 * The reference per-bundle provider of CONTRACT-240: membership enforced before matching, filters resolved for the
 * viewer (an unknown or hidden value = no rows), exact totals, pages and recents resolution.
 */
final class IssuesProvider implements FilterableSearchProviderInterface, PaginatedSearchProviderInterface, IdentifierAwareSearchProviderInterface, SearchReferenceResolverInterface
{
    public function supportedFilters(): array
    {
        return ['is', 'assignee', 'project'];
    }

    public function groupKey(): string
    {
        return 'issues';
    }

    public function search(SearchQuery $query): ?SearchGroup
    {
        $rows = $this->visible($query);

        return $rows === [] ? null : new SearchGroup('issues', 'Issues', array_slice($rows, 0, $query->limit), count($rows), null, null, true);
    }

    public function searchPage(SearchQuery $query, int $page, int $perPage): ?SearchGroup
    {
        $rows = array_slice($this->visible($query), ($page - 1) * $perPage, $perPage);

        return $rows === [] ? null : new SearchGroup('issues', 'Issues', $rows);
    }

    public function resolve(SearchReference $reference, string $userIdentifier, ?string $locale): ?SearchItem
    {
        $issue = $reference->route === 'test_issue' ? Directory::issue((int) ($reference->routeParameters['id'] ?? 0)) : null;

        return $issue !== null && Directory::isMember($userIdentifier, $issue['project']) ? $this->item($issue, null) : null;
    }

    /**
     * @return list<SearchItem>
     */
    private function visible(SearchQuery $query): array
    {
        $rows = [];
        foreach (Directory::ISSUES as $issue) {
            // Permission first: a hidden issue is never matched, counted or rendered.
            if (!Directory::isMember($query->userIdentifier, $issue['project'])) {
                continue;
            }
            if ($query->scope !== null && $query->scope !== $issue['project']) {
                continue;
            }
            if (!$this->passes($issue, $query)) {
                continue;
            }
            $score = $query->text === '' ? 0.5 : $query->score($issue['title']);
            if ($score !== null) {
                $rows[] = $this->item($issue, $score);
            }
        }

        return $rows;
    }

    /**
     * @param array{id: int, project: string, title: string, open: bool, assignee: string, date: string} $issue
     */
    private function passes(array $issue, SearchQuery $query): bool
    {
        foreach ($query->filters as $filter) {
            $match = match ($filter->key) {
                'is'       => ($filter->value === 'open') === $issue['open'] && in_array($filter->value, ['open', 'closed'], true),
                'assignee' => $issue['assignee'] === ($filter->value === 'me' ? $query->userIdentifier : $filter->value),
                'project'  => $issue['project'] === $filter->value,
                default    => true,
            };
            if ($match === $filter->negated) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{id: int, project: string, title: string, open: bool, assignee: string, date: string} $issue
     */
    private function item(array $issue, ?float $score): SearchItem
    {
        return new SearchItem($issue['title'], '/issues/' . $issue['id'], Directory::PROJECTS[$issue['project']], 'tabler:bug', null, 'issues', $score, new DateTimeImmutable($issue['date']));
    }
}
