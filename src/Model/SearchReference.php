<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Model;

/**
 * A recent pick as the browser stores it when `recent.revalidate` is on (1.8.0): a reference (URL + row kind), never
 * the title or the subtitle. The bundle matches the URL to a route and hands the reference to the
 * SearchReferenceResolverInterface services, after the SearchAccessCheckerInterface allowed it.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchReference
{
    /**
     * @param string $url Same-site path the row pointed to
     * @param string $kind Row kind (`[a-z0-9_-]`)
     * @param string $route Route the URL matches
     * @param array<string, scalar|null> $routeParameters Matched route parameters (no `_` keys)
     */
    public function __construct(
        public string $url,
        public string $kind,
        public string $route,
        public array $routeParameters = [],
    ) {
    }
}
