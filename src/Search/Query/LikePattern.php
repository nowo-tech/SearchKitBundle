<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search\Query;

use InvalidArgumentException;

use function implode;
use function preg_match;
use function sprintf;
use function str_replace;

/**
 * Escaped SQL LIKE patterns (1.9.0): `%`, `_` and the escape character `!` itself are escaped with `!`, and every
 * condition says `ESCAPE '!'`, so the typed text never acts as a wildcard on MySQL, MariaDB, PostgreSQL or SQLite (a
 * backslash stays a literal character: it is not the escape character here).
 *
 * The same conditions work in Doctrine DQL (`LOWER(t.title) LIKE :p ESCAPE '!'`). Stateless.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class LikePattern
{
    /** The escape character of every pattern. */
    public const string ESCAPE = '!';

    /** A searchable field: `column` or `alias.column` (letters, digits and `_`). */
    public const string FIELD_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';

    /** A parameter name (letters, digits and `_`). */
    private const string PARAMETER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * Escapes the LIKE wildcards of a text (`!` → `!!`, `%` → `!%`, `_` → `!_`).
     *
     * @param string $text Raw text
     *
     * @return string The text, matched literally by a pattern with `ESCAPE '!'`
     */
    public static function escape(string $text): string
    {
        return str_replace([self::ESCAPE, '%', '_'], [self::ESCAPE . self::ESCAPE, self::ESCAPE . '%', self::ESCAPE . '_'], $text);
    }

    /**
     * The pattern of "contains this text".
     *
     * @param string $text Raw text
     *
     * @return string `%<escaped text>%`
     */
    public static function contains(string $text): string
    {
        return '%' . self::escape($text) . '%';
    }

    /**
     * Whether a field name is safe to write into SQL or DQL.
     *
     * @param string $field `column` or `alias.column`
     *
     * @return bool True when it matches FIELD_PATTERN
     */
    public static function isField(string $field): bool
    {
        return preg_match(self::FIELD_PATTERN, $field) === 1;
    }

    /**
     * The case-insensitive condition of one field: `LOWER(field) LIKE :parameter ESCAPE '!'` (bind the lower-case
     * contains() pattern).
     *
     * @param string $field `column` or `alias.column`
     * @param string $parameter Parameter name, without the colon
     *
     * @throws InvalidArgumentException When the field or the parameter name is not safe
     *
     * @return string The condition
     */
    public static function condition(string $field, string $parameter): string
    {
        if (!self::isField($field)) {
            throw new InvalidArgumentException(sprintf('A searchable field must be "column" or "alias.column", got "%s".', $field));
        }
        if (preg_match(self::PARAMETER_PATTERN, $parameter) !== 1) {
            throw new InvalidArgumentException('A LIKE parameter name may only contain letters, digits and "_".');
        }

        return sprintf("LOWER(%s) LIKE :%s ESCAPE '%s'", $field, $parameter, self::ESCAPE);
    }

    /**
     * One parameter matched against several fields: `(LOWER(a) LIKE :p ESCAPE '!' OR LOWER(b) LIKE :p ESCAPE '!')`.
     *
     * @param list<string> $fields Searchable fields (at least one)
     * @param string $parameter Parameter name, without the colon
     *
     * @throws InvalidArgumentException When no field is given, or one is not safe
     *
     * @return string The condition
     */
    public static function anyField(array $fields, string $parameter): string
    {
        if ($fields === []) {
            throw new InvalidArgumentException('A LIKE search needs at least one searchable field.');
        }
        $conditions = [];
        foreach ($fields as $field) {
            $conditions[] = self::condition($field, $parameter);
        }

        return '(' . implode(' OR ', $conditions) . ')';
    }
}
