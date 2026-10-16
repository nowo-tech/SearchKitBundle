<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Model;

use InvalidArgumentException;

/**
 * One row of a group. Text is plain (the browser escapes it and marks the match with `<mark>` through DOM nodes);
 * the icon is a UX Icons name rendered by the endpoint with the application's own icon set.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchItem
{
    /**
     * @param string $title Main text
     * @param string $url Target: a path (`/…`) or an http(s) URL — anything else (`javascript:`, `data:`, `//host`) is rejected
     * @param string $subtitle Secondary text (project, breadcrumb, status, …)
     * @param string|null $icon UX Icons name (e.g. `tabler:bug`); null uses the icon of the row kind
     * @param SearchBadge|null $badge Optional pill
     * @param string|null $kind Row kind (icon template, recents); null takes the group key
     *
     * @throws InvalidArgumentException When the URL is not a path or an http(s) URL
     */
    public function __construct(
        public string $title,
        public string $url,
        public string $subtitle = '',
        public ?string $icon = null,
        public ?SearchBadge $badge = null,
        public ?string $kind = null,
    ) {
        if (!self::isSafeUrl($url)) {
            throw new InvalidArgumentException('A search item URL must be a path starting with "/" or an http(s) URL.');
        }
    }

    /**
     * Whether a URL may be the target of a row (a same-site path or an http(s) URL).
     *
     * @param string $url The URL
     *
     * @return bool True for `/path…` (not `//host`) and `http(s)://…`
     */
    public static function isSafeUrl(string $url): bool
    {
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//') && !str_starts_with($url, '/\\');
        }

        return preg_match('#^https?://[^\s/]+#i', $url) === 1;
    }

    /**
     * Row as sent to the browser.
     *
     * @param string $groupKey Key of the group (default kind)
     * @param string|null $iconSvg The rendered icon (trusted SVG from the icon set), or null for the kind icon
     *
     * @return array{title: string, subtitle: string, url: string, kind: string, icon: string|null, badge: array{label: string, tone: string}|null} The row
     */
    public function toArray(string $groupKey, ?string $iconSvg = null): array
    {
        return [
            'title'    => $this->title,
            'subtitle' => $this->subtitle,
            'url'      => $this->url,
            'kind'     => $this->kind ?? $groupKey,
            'icon'     => $iconSvg,
            'badge'    => $this->badge?->toArray(),
        ];
    }
}
