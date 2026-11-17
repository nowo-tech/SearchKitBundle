<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Controller;

use DateTimeImmutable;
use Nowo\SearchKitBundle\Http\EndpointGuard;
use Nowo\SearchKitBundle\Icon\SearchIcons;
use Nowo\SearchKitBundle\Model\SearchFilter;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchScope;
use Nowo\SearchKitBundle\Search\Ranking\SearchRanker;
use Nowo\SearchKitBundle\Search\SearchEngine;
use Nowo\SearchKitBundle\Search\SearchRequest;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;
use Twig\Markup;

use function count;
use function in_array;

/**
 * `GET {prefix}/results?q=…&scope=…&group=…&page=…` (1.8.0, `results_page.enabled`): the full results page.
 *
 * - "All" tab: every visible group with up to `results_page.per_group` rows and a *Top results* section (the best
 *   scored rows across groups);
 * - one tab per group, its count being the number of **returned** rows (never a provider total); a group whose
 *   provider implements PaginatedSearchProviderInterface pages with previous / next links (`per_page` rows);
 * - the typed filters as chips (each one links to the same search without it).
 *
 * Guests get the firewall's entry point (AccessDeniedException), users without `role` a 403; the per-user rate limit of
 * the endpoint applies (429 page). 404 when the page is off. Never cached, never indexed. The template is
 * `results_page.template`, which extends `results_page.layout` (the host layout: blocks `title` and `body`).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class ResultsController
{
    public const string ROUTE = 'nowo_search_kit_results';

    /** Group key pattern of the `group` parameter. */
    private const string GROUP = '/^[a-z0-9_-]{1,64}$/';

    /**
     * @param SearchEngine $engine Runs the providers
     * @param Security $security Current user and role check
     * @param EndpointGuard $guard Rate limit
     * @param SearchIcons $icons Renders the row icons
     * @param Environment $twig Renders the page
     * @param UrlGeneratorInterface $urls Builds tab, chip and page links
     * @param SearchRanker $ranker Top results
     * @param array{enabled: bool, template: string, layout: string, per_group: int, per_page: int, top_results: int, max_page: int} $settings `results_page`
     * @param string $role Attribute required to search
     * @param int $maxLength Longer queries are cut
     */
    public function __construct(
        private SearchEngine $engine,
        private Security $security,
        private EndpointGuard $guard,
        private SearchIcons $icons,
        private Environment $twig,
        private UrlGeneratorInterface $urls,
        private SearchRanker $ranker,
        private array $settings,
        private string $role = 'IS_AUTHENTICATED',
        private int $maxLength = 200,
    ) {
    }

    /**
     * Renders the results page.
     *
     * @param Request $request The request (`q`, optional `scope`, `group`, `page`)
     *
     * @throws NotFoundHttpException When the page is off
     * @throws AccessDeniedException For guests (entry point) and users without `role` (403)
     *
     * @return Response The page (429 when rate limited)
     */
    #[Route('/results', name: self::ROUTE, methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->settings['enabled']) {
            throw new NotFoundHttpException();
        }
        $user = $this->security->getUser();
        if (!$user instanceof UserInterface || !$this->security->isGranted($this->role)) {
            throw new AccessDeniedException();
        }

        $q     = trim(mb_substr($request->query->getString('q'), 0, $this->maxLength));
        $scope = $request->query->getString('scope');
        $scope = preg_match(SearchScope::ID_PATTERN, $scope) === 1 ? $scope : null;
        $group = $request->query->getString('group');
        $group = preg_match(self::GROUP, $group) === 1 ? $group : null;
        $page  = max(1, min($this->settings['max_page'], $request->query->getInt('page', 1)));

        $view   = ['query' => $q, 'scope' => $scope, 'group' => null, 'tabs' => [], 'groups' => [], 'top' => [], 'rows' => [], 'filters' => [], 'page' => $page, 'prev' => null, 'next' => null, 'error' => null, 'actions_only' => false];
        $status = Response::HTTP_OK;
        if (!$this->guard->accepts($user)) {
            $view['error'] = 'rate_limited';
            $status        = Response::HTTP_TOO_MANY_REQUESTS;
        } elseif ($q !== '') {
            $view = $this->search($view, $user, $request, $group, $page);
        }

        $response = new Response($this->twig->render($this->settings['template'], [
            'nowo_search_results' => $view,
            'nowo_search_layout'  => $this->settings['layout'],
        ]), $status);
        foreach (EndpointGuard::HEADERS as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $view
     *
     * @return array<string, mixed>
     */
    private function search(array $view, UserInterface $user, Request $request, ?string $group, int $page): array
    {
        $q     = (string) $view['query'];
        $scope = $view['scope'] !== null ? (string) $view['scope'] : null;
        // One engine run per page view (audit SP-I2): a group whose provider does not page is read from this run with
        // `per_page` rows; a paginated group runs only its own provider once more.
        $paged  = $group !== null && $this->engine->isPaginated($group);
        $limit  = $group !== null && !$paged ? $this->settings['per_page'] : $this->settings['per_group'];
        $search = new SearchRequest($q, $user, null, $scope, $request->getLocale(), null, $limit);
        $result = $this->engine->execute($search);

        $view['actions_only'] = $result->actionsOnly;
        $view['filters']      = array_map(fn (SearchFilter $filter): array => $filter->toArray() + ['remove' => $this->link($this->without($result->filters, $filter, $result->query->text ?? ''), $scope, null, 1)], $result->filters);
        $view['tabs']         = array_map(fn (SearchGroup $g): array => ['key' => $g->key, 'label' => $g->label, 'count' => count($g->items), 'url' => $this->link($q, $scope, $g->key, 1)], $result->groups);
        $keys                 = array_column($view['tabs'], 'key');

        if ($group === null || !in_array($group, $keys, true)) {
            $view['groups'] = array_map(fn (SearchGroup $g): array => $this->groupView($g, $q, $scope), $result->groups);
            $view['top']    = array_map(fn (array $row): array => $this->row($row['item'], $row['group']), $this->ranker->top($result->groups, $this->settings['top_results'], new DateTimeImmutable()));

            return $view;
        }

        $view['group'] = $group;
        $perPage       = $this->settings['per_page'];
        if ($paged) {
            $rows = $this->engine->page($search, $group, $page, $perPage)->items ?? [];
            if ($page > 1) {
                $view['prev'] = $this->link($q, $scope, $group, $page - 1);
            }
            if (count($rows) >= $perPage && $page < $this->settings['max_page']) {
                $view['next'] = $this->link($q, $scope, $group, $page + 1);
            }
        } else {
            $rows = [];
            foreach ($result->groups as $candidate) {
                if ($candidate->key === $group) {
                    $rows = $candidate->items;
                }
            }
        }
        $view['rows'] = array_map(fn (SearchItem $item): array => $this->row($item, $group), $rows);

        return $view;
    }

    /**
     * @return array<string, mixed>
     */
    private function groupView(SearchGroup $group, string $q, ?string $scope): array
    {
        return [
            'key'   => $group->key,
            'label' => $group->label,
            'url'   => $this->link($q, $scope, $group->key, 1),
            'rows'  => array_map(fn (SearchItem $item): array => $this->row($item, $group->key), $group->items),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(SearchItem $item, string $groupKey): array
    {
        $row         = $item->toArray($groupKey);
        $kind        = (string) preg_replace('/[^a-z0-9_-]/i', '', $row['kind']);
        $svg         = ($item->icon !== null ? $this->icons->render($item->icon) : null) ?? $this->icons->forKey($kind);
        $row['kind'] = $kind;
        $row['icon'] = new Markup($svg, 'UTF-8');

        return $row;
    }

    /**
     * The query without one filter (other filters first, then the text).
     *
     * @param list<SearchFilter> $filters
     */
    private function without(array $filters, SearchFilter $removed, string $text): string
    {
        $tokens = [];
        foreach ($filters as $filter) {
            if ($filter !== $removed) {
                $tokens[] = $filter->toToken();
            }
        }
        $tokens[] = $text;

        return trim(implode(' ', $tokens));
    }

    private function link(string $q, ?string $scope, ?string $group, int $page): string
    {
        $parameters = ['q' => $q];
        if ($scope !== null) {
            $parameters['scope'] = $scope;
        }
        if ($group !== null) {
            $parameters['group'] = $group;
        }
        if ($page > 1) {
            $parameters['page'] = $page;
        }

        return $this->urls->generate(self::ROUTE, $parameters);
    }
}
