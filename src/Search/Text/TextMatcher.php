<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search\Text;

use function count;
use function strlen;

/**
 * Scores how well a haystack matches a needle, after case and accent folding (1.8.0). Used by
 * SearchQuery::matches() / SearchQuery::score() and by the bundle's in-memory groups (pages, actions).
 *
 * Tiers (higher is better): equal 1.0, haystack starts with the needle 0.9, a word starts with it 0.8, contains 0.7,
 * every needle word prefixes a haystack word (any order) 0.6, and — only when fuzzy matching is on — every needle word
 * within the edit distance of its length (0 below 4 letters, 1 up to 6, 2 from 7) of a haystack word: 0.5 minus 0.05
 * per edit.
 *
 * Bounded cost: the needle is cut to MAX_NEEDLE characters and MAX_WORDS words, the haystack to MAX_HAYSTACK
 * characters and 64 words, so one comparison never costs more than a few thousand character steps whatever the input.
 * Pure functions, no state.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TextMatcher
{
    /** Longest needle compared (characters). */
    public const int MAX_NEEDLE = 64;

    /** Most needle words compared. */
    public const int MAX_WORDS = 8;

    /** Longest haystack compared (characters). */
    public const int MAX_HAYSTACK = 512;

    /** Longest word compared by edit distance (bytes, after folding). */
    private const int MAX_FUZZY_WORD = 32;

    /**
     * Score of one haystack for one needle.
     *
     * @param string $needle What the viewer typed (or one alternative of it)
     * @param string $haystack Text of the candidate
     * @param bool $fuzzy Allow typos (edit distance per word length)
     *
     * @return float|null The score in ]0, 1], or null when it does not match
     */
    public static function score(string $needle, string $haystack, bool $fuzzy = false): ?float
    {
        $needle   = TextNormalizer::fold(mb_substr($needle, 0, self::MAX_NEEDLE));
        $haystack = TextNormalizer::fold(mb_substr($haystack, 0, self::MAX_HAYSTACK));
        if ($needle === '' || $haystack === '') {
            return null;
        }
        if ($haystack === $needle) {
            return 1.0;
        }
        if (str_starts_with($haystack, $needle)) {
            return 0.9;
        }
        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '/u', $haystack) === 1) {
            return 0.8;
        }
        if (str_contains($haystack, $needle)) {
            return 0.7;
        }

        $needleWords   = TextNormalizer::words($needle, self::MAX_WORDS);
        $haystackWords = TextNormalizer::words($haystack);
        if (self::everyWordPrefixes($needleWords, $haystackWords)) {
            return 0.6;
        }

        return $fuzzy && $needleWords !== [] ? self::fuzzy($needleWords, $haystackWords) : null;
    }

    /**
     * Edit distance a word of this length may have and still match (0: exact only).
     *
     * @param string $word A folded word
     *
     * @return int 0 below 4 letters, 1 up to 6, 2 from 7
     */
    public static function allowedEdits(string $word): int
    {
        $length = mb_strlen($word);

        return $length < 4 ? 0 : ($length <= 6 ? 1 : 2);
    }

    /**
     * @param list<string> $needleWords
     * @param list<string> $haystackWords
     */
    private static function everyWordPrefixes(array $needleWords, array $haystackWords): bool
    {
        if (count($needleWords) < 2) {
            return false;
        }
        foreach ($needleWords as $word) {
            $found = false;
            foreach ($haystackWords as $candidate) {
                if (str_starts_with($candidate, $word)) {
                    $found = true;

                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $needleWords
     * @param list<string> $haystackWords
     */
    private static function fuzzy(array $needleWords, array $haystackWords): ?float
    {
        $edits = 0;
        foreach ($needleWords as $word) {
            $allowed = self::allowedEdits($word);
            if ($allowed === 0 || strlen($word) > self::MAX_FUZZY_WORD) {
                return null;
            }
            $best = null;
            foreach ($haystackWords as $candidate) {
                // Compare with the candidate and with its prefix of the same length (typo while still typing).
                $candidate = substr($candidate, 0, self::MAX_FUZZY_WORD);
                $distance  = min(levenshtein($word, $candidate), levenshtein($word, substr($candidate, 0, strlen($word))));
                if ($distance <= $allowed && ($best === null || $distance < $best)) {
                    $best = $distance;
                }
            }
            if ($best === null) {
                return null;
            }
            $edits += $best;
        }

        return max(0.1, 0.5 - 0.05 * $edits);
    }
}
