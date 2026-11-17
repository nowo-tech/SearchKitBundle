<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search\Text;

use function count;
use function strlen;

/**
 * A text folded once (case, accents, whitespace) and cut to its matching bound, with its words: what TextMatcher
 * compares (audit SP-M1: a query folds each term once, a candidate folds each haystack once, never per comparison).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class FoldedText
{
    /** Longest text (bytes) wordsOf() folds together with others: two bytes per word at least, so 64 words at most. */
    private const int SHORT_TEXT = 128;

    /**
     * @param string $text The folded text
     * @param list<string> $words Its words (bounded; empty for a haystack: see words())
     */
    private function __construct(
        public string $text,
        public array $words,
    ) {
    }

    /**
     * A needle: cut to TextMatcher::MAX_NEEDLE characters and TextMatcher::MAX_WORDS words.
     *
     * @param string $needle What the viewer typed (or one alternative of it)
     *
     * @return self The folded needle (empty text when nothing is left)
     */
    public static function needle(string $needle): self
    {
        $text = TextNormalizer::fold(mb_substr($needle, 0, TextMatcher::MAX_NEEDLE));

        return new self($text, TextNormalizer::words($text, TextMatcher::MAX_WORDS));
    }

    /**
     * A haystack: cut to TextMatcher::MAX_HAYSTACK characters. Its words are split only when a typo comparison needs
     * them (words()), not for every candidate text.
     *
     * @param string $haystack Text of a candidate
     *
     * @return self The folded haystack
     */
    public static function haystack(string $haystack): self
    {
        $text = TextNormalizer::fold(mb_substr($haystack, 0, TextMatcher::MAX_HAYSTACK));

        return new self($text, []);
    }

    /**
     * The distinct words of several haystacks (1.8.1): the words() of each FoldedText::haystack() merged, at the cost of
     * one fold when every text is short. A text of at most SHORT_TEXT bytes has at most 64 words and 512 characters,
     * so neither bound of haystack() / words() can cut it, and folding the texts together gives the same words.
     *
     * @param list<string> $texts Raw texts of a candidate
     *
     * @return list<string> The distinct folded words
     */
    public static function wordsOf(array $texts): array
    {
        foreach ($texts as $text) {
            if (strlen($text) > self::SHORT_TEXT) {
                return array_values(array_unique(array_merge(...array_map(static fn (string $text): array => self::haystack($text)->words(), $texts))));
            }
        }

        return array_values(array_unique(TextNormalizer::words(TextNormalizer::fold(implode(' ', $texts)), 64 * count($texts))));
    }

    /**
     * The words of the text: the needle's own (bounded at construction) or, for a haystack, split now (64 at most).
     *
     * @return list<string> The words
     */
    public function words(): array
    {
        return $this->words !== [] ? $this->words : TextNormalizer::words($this->text);
    }
}
