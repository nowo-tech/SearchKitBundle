<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search;

use function array_slice;
use function count;
use function in_array;
use function is_string;
use function strlen;

/**
 * One `min_length_exempt_patterns` entry: the regular expression that means the same in PCRE and in ECMAScript.
 *
 * The browser runs `new RegExp(pattern, 'u')` and the server the same source, so a pattern is accepted only when it
 * stays inside a subset both engines read alike: literals, `\d \D`, escaped syntax characters, character
 * classes, `(…)`, `(?:…)`, `|`, `^`, `$` and the quantifiers `* + ? {n} {n,} {n,m}` (lazy or not). Everything else is
 * refused rather than translated, because each of these parses in only one dialect or means something else in the
 * other: `.` and `\s` (line breaks, Unicode spaces), `\w \W \b \B` (server locale), `{0}`, possessive quantifiers, atomic groups, inline flags, lookaround,
 * backreferences, `\p{…}`, `\x{…}`, `\u{…}`, POSIX classes.
 *
 * PHP compiles it with `(*UTF)` and `D`, not with the `u` modifier: `u` also turns on PCRE2_UCP, which would make `\d`
 * match `٧` on the server but not in the browser. `D` keeps `$` from matching before a final line break.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class MinLengthExemptPattern
{
    /** A control character: the subset refuses them, so it can never appear inside the delimiters. */
    private const string DELIMITER = "\x01";

    private const array CLASS_SHORTHANDS = ['d', 'D'];

    private const array SYNTAX_ESCAPES = ['^', '$', '\\', '.', '*', '+', '?', '(', ')', '[', ']', '{', '}', '|', '/'];

    /**
     * What is wrong with a pattern.
     *
     * @param mixed $pattern The configured value
     *
     * @return string|null Why it is refused, or null when it is valid
     */
    public static function problem(mixed $pattern): ?string
    {
        if (!is_string($pattern) || $pattern === '') {
            return 'it must be a non-empty string';
        }
        if (!mb_check_encoding($pattern, 'UTF-8')) {
            return 'it is not valid UTF-8';
        }
        $problem = self::scan(mb_str_split($pattern, 1, 'UTF-8'));
        if ($problem !== null) {
            return $problem;
        }

        return @preg_match(self::regex($pattern), '') === false
            ? 'it does not compile (unbalanced group, reversed range or a repeat count that is too large)'
            : null;
    }

    /**
     * Whether a pattern matches a text. Invalid UTF-8 is no match.
     *
     * @param string $pattern A pattern accepted by problem()
     * @param string $text The trimmed query
     *
     * @return bool|null True when it matches; null on an engine error (preg_last_error_msg() says which)
     */
    public static function matches(string $pattern, string $text): ?bool
    {
        // (*UTF) does not set PHP's own UTF flag, so PHP tells PCRE2 not to check the subject: check it here.
        if (!mb_check_encoding($text, 'UTF-8')) {
            return false;
        }
        $result = preg_match(self::regex($pattern), $text);

        return $result === false ? null : $result === 1;
    }

    private static function regex(string $pattern): string
    {
        return self::DELIMITER . '(*UTF)' . $pattern . self::DELIMITER . 'D';
    }

    /**
     * @param list<string> $chars The pattern, one code point per entry
     */
    private static function scan(array $chars): ?string
    {
        $count        = count($chars);
        $quantifiable = false;
        for ($i = 0; $i < $count; ++$i) {
            $char = $chars[$i];
            if (self::isControl($char)) {
                return 'control characters are not allowed';
            }
            switch ($char) {
                case '\\':
                    $next = $chars[++$i] ?? '';
                    if (in_array($next, ['w', 'W', 'b', 'B'], true)) {
                        return '"\\' . $next . '" follows the server locale in PCRE (a single-byte setlocale() makes it match "é"); use a character class such as [A-Za-z0-9_]';
                    }
                    if (in_array($next, self::CLASS_SHORTHANDS, true) || in_array($next, self::SYNTAX_ESCAPES, true)) {
                        $quantifiable = true;
                    } else {
                        return '"\\' . $next . '" is not read alike by PCRE and JavaScript';
                    }

                    break;
                case '[':
                    $end = self::scanClass($chars, $i);
                    if (is_string($end)) {
                        return $end;
                    }
                    $i            = $end;
                    $quantifiable = true;

                    break;
                case '(':
                    if (($chars[$i + 1] ?? '') === '?') {
                        if (($chars[$i + 2] ?? '') !== ':') {
                            return 'only plain groups and "(?:" are allowed (no lookaround, named or atomic groups, or inline flags)';
                        }
                        $i += 2;
                    }
                    $quantifiable = false;

                    break;
                case ')':
                    $quantifiable = true;

                    break;
                case '|':
                case '^':
                case '$':
                    $quantifiable = false;

                    break;
                case '*':
                case '+':
                case '?':
                case '{':
                    if (!$quantifiable) {
                        return 'quantifier "' . $char . '" has nothing to repeat (possessive and stacked quantifiers are refused)';
                    }
                    if ($char === '{') {
                        if (preg_match('/\A\{\d+(?:,\d*)?\}/', implode('', array_slice($chars, $i)), $match) !== 1) {
                            return '"{" must start {n}, {n,} or {n,m}; escape a literal brace';
                        }
                        // PCRE2 10.42-10.44 read a zero-repeated group ending in "^" or "\b" as an anchor; V8 does not.
                        if (preg_match('/\A\{0+(?:,0+)?\}\z/', $match[0]) === 1) {
                            return 'a repeat of zero ("{0}", "{0,0}") is refused: PCRE and JavaScript disagree on it';
                        }
                        $i += strlen($match[0]) - 1;
                    }
                    if (($chars[$i + 1] ?? '') === '?') {
                        ++$i;
                    }
                    $quantifiable = false;

                    break;
                case '.':
                    return '"." differs on line breaks between PCRE and JavaScript; use a character class';
                case ']':
                case '}':
                    return 'a literal "' . $char . '" must be escaped';
                default:
                    $quantifiable = true;
            }
        }

        return null;
    }

    /**
     * @param list<string> $chars The pattern, one code point per entry
     * @param int $open Index of the opening `[`
     *
     * @return int|string Index of the closing `]`, or why the class is refused
     */
    private static function scanClass(array $chars, int $open): int|string
    {
        $i = $open + 1;
        if (($chars[$i] ?? '') === '^') {
            ++$i;
        }
        if (($chars[$i] ?? '') === ']') {
            return 'empty character classes mean different things in PCRE and JavaScript';
        }
        $first = true;
        while (($chars[$i] ?? null) !== ']') {
            if (($chars[$i] ?? null) === '-' && ($first || ($chars[$i + 1] ?? null) === ']')) {
                $atom = 1;
            } else {
                $atom = self::classAtom($chars, $i);
                if (is_string($atom)) {
                    return $atom;
                }
            }
            $single = $atom !== 0;
            $i += $single ? $atom : 2;
            $first = false;
            if (($chars[$i] ?? null) !== '-' || ($chars[$i + 1] ?? null) === ']') {
                continue;
            }
            $end = self::classAtom($chars, $i + 1);
            if (is_string($end)) {
                return $end;
            }
            if (!$single || $end === 0) {
                return 'a range in a character class needs a single character at each end';
            }
            $i += 1 + $end;
        }

        return $i;
    }

    /**
     * Reads one member of a character class.
     *
     * @param list<string> $chars The pattern, one code point per entry
     * @param int $i Index of the member
     *
     * @return int|string Code points used by a single character (0 for a two-character shorthand such as `\d`), or why it is refused
     */
    private static function classAtom(array $chars, int $i): int|string
    {
        $char = $chars[$i] ?? null;
        if ($char === null) {
            return 'unterminated character class';
        }
        if (self::isControl($char)) {
            return 'control characters are not allowed';
        }
        if ($char === '[') {
            return 'a literal "[" in a character class must be escaped (POSIX classes are refused)';
        }
        if ($char === '-') {
            return 'a literal "-" in a character class must be first, last or escaped';
        }
        if ($char !== '\\') {
            return 1;
        }
        $next = $chars[$i + 1] ?? '';
        if (in_array($next, self::CLASS_SHORTHANDS, true)) {
            return 0;
        }

        return in_array($next, self::SYNTAX_ESCAPES, true) || $next === '-'
            ? 2
            : '"\\' . $next . '" is not read alike by PCRE and JavaScript in a character class';
    }

    private static function isControl(string $char): bool
    {
        return preg_match('/\A[\x00-\x1F\x7F]\z/', $char) === 1;
    }
}
