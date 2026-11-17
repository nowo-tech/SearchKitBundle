<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Model;

use InvalidArgumentException;

use function count;

/**
 * One command of the palette (SearchActionProviderInterface, 1.8.0): a GET link, never a form or a POST.
 *
 * Keywords are matched, never shown. The action is listed only when the SearchAccessCheckerInterface allows its URL
 * for the viewer (deny by default).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchAction
{
    /** Most keywords kept per action. */
    public const int MAX_KEYWORDS = 32;

    /** @var list<string> */
    public array $keywords;

    /**
     * @param string $label Translated text of the row
     * @param string $url Target (a path or an http(s) URL; opened with GET)
     * @param list<string> $keywords Extra words that find the action (any locale); never shown
     * @param string|null $icon UX Icons name, or null for the `action` kind icon
     * @param string|null $shortcut Key hint shown at the end of the row (e.g. `g p`); display only
     * @param string|null $context Translated hint shown under the label (e.g. the project it acts on)
     *
     * @throws InvalidArgumentException When the label is empty or the URL is not a path or an http(s) URL
     */
    public function __construct(
        public string $label,
        public string $url,
        array $keywords = [],
        public ?string $icon = null,
        public ?string $shortcut = null,
        public ?string $context = null,
    ) {
        if (trim($label) === '') {
            throw new InvalidArgumentException('A search action needs a label.');
        }
        if (!SearchItem::isSafeUrl($url)) {
            throw new InvalidArgumentException('A search action URL must be a path starting with "/" or an http(s) URL.');
        }
        $words = [];
        foreach ($keywords as $keyword) {
            $keyword = trim($keyword);
            if ($keyword !== '' && count($words) < self::MAX_KEYWORDS) {
                $words[] = $keyword;
            }
        }
        $this->keywords = $words;
    }

    /**
     * The action as a row of the Actions group.
     *
     * @param float|null $score Relevance for the current query, or null
     *
     * @return SearchItem The row (kind `action`)
     */
    public function toItem(?float $score = null): SearchItem
    {
        return new SearchItem($this->label, $this->url, $this->context ?? '', $this->icon, null, 'action', $score, null, $this->shortcut);
    }
}
