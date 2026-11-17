<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Controller;

use JsonException;
use Nowo\SearchKitBundle\Http\EndpointGuard;
use Nowo\SearchKitBundle\Icon\SearchIcons;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Search\Recent\RecentResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function is_array;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * `POST {prefix}/recent` (1.8.0, `recent.revalidate`): `{refs: [{url, kind}]}` → `{items: [{title, subtitle, url, kind,
 * icon, badge}]}`, the rows of the references the viewer may still open, in order.
 *
 * Guarded like the search endpoint (signed-in, `role`, same per-user rate limit) plus a CSRF token in the
 * `X-CSRF-Token` header (id CSRF_TOKEN_ID; 403 `{error: "csrf"}` when missing or invalid). Never cached. 404 when
 * revalidation is off. A malformed body answers `{items: []}`, exactly like references that are all hidden.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class RecentController
{
    public const string ROUTE = 'nowo_search_kit_recent';

    /** CSRF token id of the revalidation endpoint. */
    public const string CSRF_TOKEN_ID = 'nowo_search_kit_recent';

    /** Largest body read (bytes). */
    private const int MAX_BODY = 32768;

    /**
     * @param EndpointGuard $guard User, role and rate limit
     * @param RecentResolver $resolver Revalidates the references
     * @param SearchIcons $icons Renders the row icons
     * @param bool $enabled `recent.enabled` and `recent.revalidate`
     * @param int $max `recent.max`
     * @param CsrfTokenManagerInterface|null $csrf Token manager (null: every call is refused)
     */
    public function __construct(
        private EndpointGuard $guard,
        private RecentResolver $resolver,
        private SearchIcons $icons,
        private bool $enabled = false,
        private int $max = 8,
        private ?CsrfTokenManagerInterface $csrf = null,
    ) {
    }

    /**
     * Revalidates the references.
     *
     * @param Request $request The request (JSON body, `X-CSRF-Token` header)
     *
     * @throws NotFoundHttpException When revalidation is off
     *
     * @return JsonResponse `{items}`; 401 / 403 / 429 like the search endpoint
     */
    #[Route('/recent', name: self::ROUTE, methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->enabled) {
            throw new NotFoundHttpException();
        }
        $user = $this->guard->check();
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $token = $request->headers->get('X-CSRF-Token', '');
        if (!$this->csrf instanceof CsrfTokenManagerInterface || $token === '' || !$this->csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, (string) $token))) {
            return EndpointGuard::json(['error' => 'csrf'], Response::HTTP_FORBIDDEN);
        }

        $items = $this->resolver->resolve($this->references($request), $user->getUserIdentifier(), $request->getLocale(), $this->max);

        return EndpointGuard::json(['items' => array_map(
            fn (SearchItem $item): array => $item->toArray('recent', $item->icon !== null ? $this->icons->render($item->icon) : null),
            $items,
        )]);
    }

    /**
     * @return array<mixed>
     */
    private function references(Request $request): array
    {
        $body = $request->getContent();
        if ($body === '' || strlen($body) > self::MAX_BODY) {
            return [];
        }
        try {
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        $refs = is_array($data) ? ($data['refs'] ?? null) : null;

        return is_array($refs) ? $refs : [];
    }
}
