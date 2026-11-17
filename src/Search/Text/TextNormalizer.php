<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search\Text;

use function array_slice;

/**
 * Case and accent folding for in-memory matching (1.8.0): `Contraseña` and `contrasena` compare equal, as do
 * `Übersicht` / `ubersicht` and `Straße` / `strasse`. A fixed table covers the Latin letters of the seven bundle
 * locales (en es it fr pt de nl) without depending on ext-intl; combining marks are dropped too.
 *
 * Pure functions, no state: safe in FrankenPHP worker mode.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TextNormalizer
{
    /** Letters folded to ASCII (after lower-casing). */
    private const array FOLD = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'ç' => 'c', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c', 'ď' => 'd', 'đ' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ğ' => 'g', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i',
        'ı' => 'i', 'ł' => 'l', 'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o',
        'ŕ' => 'r', 'ř' => 'r', 'ś' => 's', 'ş' => 's', 'š' => 's', 'ß' => 'ss', 'ţ' => 't', 'ť' => 't', 'ŧ' => 't',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u',
        'ų' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
        'æ' => 'ae', 'œ' => 'oe', 'ĳ' => 'ij',
    ];

    /**
     * Lower-cases, folds accents and collapses whitespace.
     *
     * @param string $text Any text
     *
     * @return string The folded text (trimmed)
     */
    public static function fold(string $text): string
    {
        $text = strtr(mb_strtolower($text, 'UTF-8'), self::FOLD);

        return trim((string) preg_replace(['/\p{Mn}+/u', '/\s+/u'], ['', ' '], $text));
    }

    /**
     * The words of a folded text (letters and digits; anything else separates).
     *
     * @param string $folded Text returned by fold()
     * @param int $max Most words returned (bounded cost)
     *
     * @return list<string> The words, in order
     */
    public static function words(string $folded, int $max = 64): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $folded, -1, PREG_SPLIT_NO_EMPTY);

        return array_slice($words === false ? [] : $words, 0, $max);
    }
}
