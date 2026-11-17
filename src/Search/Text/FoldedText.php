<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search\Text;

/**
 * A text folded once (case, accents, whitespace) and cut to its matching bound, with its words: what TextMatcher
 * compares (audit SP-M1: a query folds each term once, a candidate folds each haystack once, never per comparison).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class FoldedText
{
    /**
     * @param string $text The folded text
     * @param list<string> $words Its words (bounded)
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
     * A haystack: cut to TextMatcher::MAX_HAYSTACK characters and 64 words.
     *
     * @param string $haystack Text of a candidate
     *
     * @return self The folded haystack
     */
    public static function haystack(string $haystack): self
    {
        $text = TextNormalizer::fold(mb_substr($haystack, 0, TextMatcher::MAX_HAYSTACK));

        return new self($text, TextNormalizer::words($text));
    }
}
