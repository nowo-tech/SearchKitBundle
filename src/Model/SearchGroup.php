<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Model;

use Closure;

use function array_slice;
use function count;

/**
 * A titled group of rows with the number of matches and an optional "See all" link.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchGroup
{
    /**
     * @param string $key Stable key (`[a-z0-9_-]`, used as default row kind and `data-group`)
     * @param string $label Translated heading
     * @param list<SearchItem> $items Rows (cut to `per_group`)
     * @param int|null $total Number of matches (null: the number of rows)
     * @param string|null $moreUrl "See all" target (path or http(s) URL), or null
     * @param string|null $moreLabel "See all" text
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $items,
        public ?int $total = null,
        public ?string $moreUrl = null,
        public ?string $moreLabel = null,
    ) {
    }

    /**
     * The same group with at most `$limit` rows.
     *
     * @param int $limit Maximum rows
     *
     * @return self This group when it already fits, otherwise a cut copy
     */
    public function limit(int $limit): self
    {
        if (count($this->items) <= $limit) {
            return $this;
        }

        return new self($this->key, $this->label, array_slice($this->items, 0, $limit), $this->total ?? count($this->items), $this->moreUrl, $this->moreLabel);
    }

    /**
     * Group as sent to the browser.
     *
     * @param Closure(SearchItem): (string|null)|null $icon Renders a row icon (null: none)
     *
     * @return array{key: string, label: string, total: int, items: list<array<string, mixed>>, more: array{url: string, label: string}|null} The group
     */
    public function toArray(?Closure $icon = null): array
    {
        $key  = (string) preg_replace('/[^a-z0-9_-]/', '', strtolower($this->key));
        $more = null;
        if ($this->moreUrl !== null && SearchItem::isSafeUrl($this->moreUrl)) {
            $more = ['url' => $this->moreUrl, 'label' => $this->moreLabel ?? $this->moreUrl];
        }

        return [
            'key'   => $key,
            'label' => $this->label,
            'total' => $this->total ?? count($this->items),
            'items' => array_map(static fn (SearchItem $item): array => $item->toArray($key, $icon instanceof Closure ? $icon($item) : null), $this->items),
            'more'  => $more,
        ];
    }
}
