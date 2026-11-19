<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search\Query;

use function array_slice;
use function array_unique;
use function array_values;
use function mb_strtolower;
use function mb_substr;
use function preg_match_all;
use function preg_split;
use function trim;

use const PREG_SPLIT_NO_EMPTY;

/**
 * The words of a query (1.9.0): what a LIKE search binds, and the letters-and-digits terms that may reach a full-text
 * operator. Bounded: at most MAX_WORDS words of at most MAX_WORD_LENGTH characters. Stateless.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class SearchWords
{
    /** Most words used from one query. */
    public const int MAX_WORDS = 8;

    /** Longest word kept (longer words are cut). */
    public const int MAX_WORD_LENGTH = 64;

    /**
     * The lower-case, distinct, whitespace-separated words of a text (any character but whitespace is kept, so a
     * LIKE search must escape them: LikePattern::contains()).
     *
     * @param string $text The query text
     *
     * @return list<string> At most MAX_WORDS words
     */
    public static function words(string $text): array
    {
        $parts = preg_split('/\s+/u', trim(mb_strtolower($text)), -1, PREG_SPLIT_NO_EMPTY);

        return self::bounded($parts === false ? [] : $parts);
    }

    /**
     * The terms that may reach a full-text operator: runs of letters and digits only (no operator, quote, wildcard
     * or punctuation survives), lower-case and distinct.
     *
     * @param string $text The query text
     *
     * @return list<string> At most MAX_WORDS terms
     */
    public static function fullTextTerms(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $matches);

        return self::bounded($matches[0]);
    }

    /**
     * @param array<string> $words
     *
     * @return list<string>
     */
    private static function bounded(array $words): array
    {
        $kept = [];
        foreach ($words as $word) {
            $kept[] = mb_substr($word, 0, self::MAX_WORD_LENGTH);
        }

        return array_slice(array_values(array_unique($kept)), 0, self::MAX_WORDS);
    }
}
