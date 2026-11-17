<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search\Access;

use Nowo\SearchKitBundle\Contract\SearchAccessCheckerInterface;
use Nowo\SearchKitBundle\Model\SearchAccessRequest;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchReference;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;
use Throwable;

use function is_scalar;
use function is_string;

/**
 * Asks the SearchAccessCheckerInterface services (1.8.0). **Deny by default**: allowed only when one checker says true
 * and none says false; no checker, only null answers, or a checker that throws → denied.
 *
 * Also matches a same-site URL to its route (as a GET, whatever the method of the current request) so that recents and
 * provider rows can be checked by route and parameters. Holds no state: safe in FrankenPHP worker mode (the router
 * context is swapped only for the duration of one match and always restored).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchAccess
{
    /**
     * @param iterable<SearchAccessCheckerInterface> $checkers Tagged checkers
     * @param RouterInterface|null $router Matches URLs to routes (null: no URL can be matched, so none is allowed)
     * @param LoggerInterface|null $logger Failing checkers are reported here
     */
    public function __construct(
        private iterable $checkers = [],
        private ?RouterInterface $router = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Whether the viewer may open the target.
     *
     * @param SearchAccessRequest $request The target and the viewer
     *
     * @return bool True only when a checker allowed it and none denied it
     */
    public function isAllowed(SearchAccessRequest $request): bool
    {
        $allowed = false;
        foreach ($this->checkers as $checker) {
            try {
                $decision = $checker->decide($request);
            } catch (Throwable $exception) {
                $this->logger?->error('Search access checker {checker} failed: {exception}.', [
                    'checker'   => $checker::class,
                    'exception' => $exception::class,
                ]);

                return false;
            }
            if ($decision === false) {
                return false;
            }
            $allowed = $allowed || $decision === true;
        }

        return $allowed;
    }

    /**
     * The access request of a URL: a same-site path is matched to its route; an http(s) URL gets no route.
     *
     * @param string $url The target
     * @param string $userIdentifier The viewer
     * @param string $kind SearchAccessRequest::KIND_*
     * @param string|null $scope Scope id of the search, or null
     *
     * @return SearchAccessRequest|null The request, or null for an unsafe URL or a path that matches no GET route
     */
    public function requestForUrl(string $url, string $userIdentifier, string $kind, ?string $scope = null): ?SearchAccessRequest
    {
        if (!SearchItem::isSafeUrl($url)) {
            return null;
        }
        if (!str_starts_with($url, '/')) {
            return new SearchAccessRequest($userIdentifier, $url, null, [], $kind, null, null, $scope);
        }
        $match = $this->match($url);

        return $match === null ? null : new SearchAccessRequest($userIdentifier, $url, $match[0], $match[1], $kind, null, null, $scope);
    }

    /**
     * Whether the viewer may open a URL (requestForUrl() + isAllowed()).
     *
     * @param string $url The target
     * @param string $userIdentifier The viewer
     * @param string $kind SearchAccessRequest::KIND_*
     * @param string|null $scope Scope id of the search, or null
     *
     * @return bool False for an unsafe or unknown URL, or when the checkers do not allow it
     */
    public function allowsUrl(string $url, string $userIdentifier, string $kind, ?string $scope = null): bool
    {
        $request = $this->requestForUrl($url, $userIdentifier, $kind, $scope);

        return $request instanceof SearchAccessRequest && $this->isAllowed($request);
    }

    /**
     * The reference of a same-site path (its route and parameters).
     *
     * @param string $url A same-site path
     * @param string $kind Row kind
     *
     * @return SearchReference|null The reference, or null when the URL is not a same-site path of a GET route
     */
    public function referenceFor(string $url, string $kind): ?SearchReference
    {
        $match = str_starts_with($url, '/') && SearchItem::isSafeUrl($url) ? $this->match($url) : null;

        return $match === null ? null : new SearchReference($url, $kind, $match[0], $match[1]);
    }

    /**
     * @return array{0: string, 1: array<string, scalar|null>}|null
     */
    private function match(string $url): ?array
    {
        if (!$this->router instanceof RouterInterface) {
            return null;
        }
        $path     = (string) strtok($url, '?#');
        $original = $this->router->getContext();
        $swap     = $original->getMethod() !== 'GET';
        if ($swap) {
            // A fresh GET context for this one match; the request's own context object is put back in `finally`.
            // @igor-ignore - Scoped to this call only, restored below (FrankenPHP worker safe)
            $this->router->setContext(new RequestContext($original->getBaseUrl(), 'GET', $original->getHost(), $original->getScheme(), $original->getHttpPort(), $original->getHttpsPort(), $original->getPathInfo(), $original->getQueryString()));
        }
        try {
            $attributes = $this->router->match($path);
        } catch (Throwable) {
            return null;
        } finally {
            if ($swap) {
                // @igor-ignore - Restores the request's own context (same object as before the call)
                $this->router->setContext($original);
            }
        }
        $route      = $attributes['_route'] ?? null;
        $parameters = [];
        foreach ($attributes as $name => $value) {
            if (is_string($name) && !str_starts_with($name, '_') && ($value === null || is_scalar($value))) {
                $parameters[$name] = $value;
            }
        }

        return is_string($route) ? [$route, $parameters] : null;
    }
}
