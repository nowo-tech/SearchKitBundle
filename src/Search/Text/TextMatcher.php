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
        return self::scoreFolded(FoldedText::needle($needle), FoldedText::haystack($haystack), $fuzzy);
    }

    /**
     * Score of a folded haystack for a folded needle (no folding here: fold each text once, then compare many).
     *
     * @param FoldedText $needle FoldedText::needle()
     * @param FoldedText $haystack FoldedText::haystack()
     * @param bool $fuzzy Allow typos (edit distance per word length)
     *
     * @return float|null The score in ]0, 1], or null when it does not match
     */
    public static function scoreFolded(FoldedText $needle, FoldedText $haystack, bool $fuzzy = false): ?float
    {
        if ($needle->text === '' || $haystack->text === '') {
            return null;
        }
        if ($haystack->text === $needle->text) {
            return 1.0;
        }
        if (str_starts_with($haystack->text, $needle->text)) {
            return 0.9;
        }
        $position = strpos($haystack->text, $needle->text);
        if ($position !== false) {
            return self::atWordStart($haystack->text, $needle->text, $position) ? 0.8 : 0.7;
        }
        if (self::everyWordPrefixes($needle->words, $haystack->text)) {
            return 0.6;
        }

        return $fuzzy && $needle->words !== [] ? self::fuzzy($needle->words, $haystack->words) : null;
    }

    /**
     * Only the typo tier of scoreFolded() (for callers that ran the exact tiers first, on every text).
     *
     * @param FoldedText $needle FoldedText::needle()
     * @param FoldedText $haystack FoldedText::haystack()
     *
     * @return float|null The fuzzy score, or null
     */
    public static function fuzzyFolded(FoldedText $needle, FoldedText $haystack): ?float
    {
        return $needle->words !== [] && $haystack->words !== [] ? self::fuzzy($needle->words, $haystack->words) : null;
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
     * Whether one occurrence of the needle (the first at $position or a later one) starts a word.
     */
    private static function atWordStart(string $haystack, string $needle, int $position): bool
    {
        while ($position !== false) {
            if ($position === 0 || preg_match('/[\p{L}\p{N}]$/u', substr($haystack, 0, $position)) !== 1) {
                return true;
            }
            $position = strpos($haystack, $needle, $position + 1);
        }

        return false;
    }

    /**
     * Whether every needle word starts a word of the haystack (a substring test first, the word-boundary test after).
     *
     * @param list<string> $needleWords
     */
    private static function everyWordPrefixes(array $needleWords, string $haystack): bool
    {
        if (count($needleWords) < 2) {
            return false;
        }
        foreach ($needleWords as $word) {
            if (!str_contains($haystack, $word) || preg_match('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '/u', $haystack) !== 1) {
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
