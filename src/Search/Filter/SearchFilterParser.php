<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search\Filter;

use Nowo\SearchKitBundle\Model\SearchFilter;

use function count;
use function in_array;

/**
 * Parses typed filters out of the query text (1.8.0): `key:value`, `key:"quoted value"`, `-key:value` (negation),
 * for the keys listed in `nowo_search_kit.filters.keys` only. Any other `word:word` stays text (a time, a URL …).
 *
 * A token with an empty value (`assignee:` while the viewer is still typing) is removed from the text without
 * becoming a filter. Bounded: at most `filters.max` filters, values cut to SearchFilter::MAX_VALUE_LENGTH characters;
 * extra filters are dropped. Stateless.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchFilterParser
{
    private const string TOKEN = '/(?<![^\s])(-?)([A-Za-z][A-Za-z0-9_-]{0,31}):(?:"([^"]*)"?|(\S*))/u';

    /**
     * @param list<string> $keys Filter keys that are recognised (empty: the parser changes nothing)
     * @param int $max Most filters kept per query
     */
    public function __construct(
        private array $keys = [],
        private int $max = 5,
    ) {
    }

    /**
     * Whether any filter key is configured.
     *
     * @return bool True when the parser recognises at least one key
     */
    public function isEnabled(): bool
    {
        return $this->keys !== [];
    }

    /**
     * The configured keys.
     *
     * @return list<string> The keys
     */
    public function keys(): array
    {
        return $this->keys;
    }

    /**
     * Splits a query into its text and its filters.
     *
     * @param string $text The trimmed query
     *
     * @return array{0: string, 1: list<SearchFilter>} The text without the filter tokens (whitespace collapsed) and the filters
     */
    public function parse(string $text): array
    {
        if ($this->keys === [] || !str_contains($text, ':')) {
            return [$text, []];
        }
        $filters = [];
        $rest    = preg_replace_callback(self::TOKEN, function (array $match) use (&$filters): string {
            $key = strtolower($match[2]);
            if (!in_array($key, $this->keys, true)) {
                return $match[0];
            }
            $value = trim(($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? ''));
            if ($value !== '' && count($filters) < $this->max) {
                $filters[] = new SearchFilter($key, mb_substr($value, 0, SearchFilter::MAX_VALUE_LENGTH), $match[1] === '-');
            }

            return ' ';
        }, $text);

        return [trim((string) preg_replace('/\s+/u', ' ', (string) $rest)), $filters];
    }
}
