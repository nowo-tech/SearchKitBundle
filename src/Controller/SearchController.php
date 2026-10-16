<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Controller;

use Nowo\SearchKitBundle\Icon\SearchIcons;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchScope;
use Nowo\SearchKitBundle\Search\SearchEngine;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * `GET {prefix}?q=…&scope=…` → `{query, groups: [{key, label, total, items: [{title, subtitle, url, kind, icon, badge}], more}]}`.
 *
 * Read-only (no CSRF: nothing changes), signed-in users granted `role` only, rate limited per user (429
 * `{error: "rate_limited"}`), never cached (`Cache-Control: no-store, private`). The query is cut to `max_length`;
 * a `scope` that is not a valid id is ignored (unscoped search). Results come from the providers, which only return
 * what the user may open; an inaccessible scope therefore answers 200 with no groups, never 403.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[AsController]
final readonly class SearchController
{
    public const string ROUTE = 'nowo_search_kit_search';

    private const array HEADERS = ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex'];

    /**
     * @param SearchEngine $engine Runs the providers
     * @param Security $security Current user and role check
     * @param SearchIcons $icons Renders the row icons
     * @param string $role Attribute required to search
     * @param int $maxLength Longer queries are cut
     * @param RateLimiterFactoryInterface|null $limiter Per-user limiter (null: rate_limit.enabled false)
     */
    public function __construct(
        private SearchEngine $engine,
        private Security $security,
        private SearchIcons $icons,
        private string $role = 'IS_AUTHENTICATED',
        private int $maxLength = 200,
        private ?RateLimiterFactoryInterface $limiter = null,
    ) {
    }

    /**
     * Answers one search.
     *
     * @param Request $request The request (`q`, optional `scope`)
     *
     * @throws AccessDeniedException For guests and users without `role` (the firewall turns it into login / 403)
     *
     * @return JsonResponse The groups, or 429 when the user exceeded the rate limit
     */
    #[Route('', name: self::ROUTE, methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof UserInterface || !$this->security->isGranted($this->role)) {
            throw new AccessDeniedException('Search requires a signed-in user.');
        }
        if ($this->limiter instanceof RateLimiterFactoryInterface
            && !$this->limiter->create(hash('sha256', $user->getUserIdentifier()))->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS, self::HEADERS);
        }

        $query = mb_substr($request->query->getString('q'), 0, $this->maxLength);
        $scope = $request->query->getString('scope');
        $scope = preg_match(SearchScope::ID_PATTERN, $scope) === 1 ? $scope : null;

        $groups = $this->engine->search($user, $query, $scope);

        return new JsonResponse([
            'query'  => trim($query),
            'groups' => array_map(
                fn (SearchGroup $group): array => $group->toArray(fn (SearchItem $item): ?string => $item->icon !== null ? $this->icons->render($item->icon) : null),
                $groups,
            ),
        ], Response::HTTP_OK, self::HEADERS);
    }
}
