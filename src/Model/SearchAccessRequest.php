<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Model;

/**
 * What a SearchAccessCheckerInterface decides on (1.8.0): may this viewer open this target?
 *
 * The bundle builds it for every registry page, page source entry, provider action, recent reference and — with
 * `access.filter_all_rows` — every provider row, **before** the row is matched, counted, ranked or rendered.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchAccessRequest
{
    public const string KIND_PAGE   = 'page';
    public const string KIND_ACTION = 'action';
    public const string KIND_RECENT = 'recent';
    public const string KIND_ROW    = 'row';

    /**
     * @param string $userIdentifier The viewer
     * @param string $url Target URL (a same-site path or an http(s) URL)
     * @param string|null $route Route name of the target, or null when the URL matches no route (external URL)
     * @param array<string, scalar|null> $routeParameters Route parameters (no `_` keys)
     * @param string $kind What is checked (self::KIND_*)
     * @param string|null $attribute Optional permission attribute declared by the entry (registry `attribute`)
     * @param string|null $subject Optional subject declared by the entry (registry `subject`)
     * @param string|null $scope Scope id of the search, or null
     */
    public function __construct(
        public string $userIdentifier,
        public string $url,
        public ?string $route,
        public array $routeParameters = [],
        public string $kind = self::KIND_PAGE,
        public ?string $attribute = null,
        public ?string $subject = null,
        public ?string $scope = null,
    ) {
    }
}
