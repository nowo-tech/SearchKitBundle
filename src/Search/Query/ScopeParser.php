<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search\Query;

use Nowo\SearchKitBundle\Model\SearchScope;

use function ctype_digit;
use function preg_match;
use function strlen;
use function substr;

/**
 * Reads a query scope (1.9.0). The scope comes from the browser: it is **client input**, never trusted. These helpers
 * only check its shape; the provider must still check in SQL that the viewer may search the scoped object (same
 * tenant, visible), and treat a scope it does not know exactly like a scope with no match. Stateless.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ScopeParser
{
    /** Allowed value after the prefix (no ":" in it). */
    private const string VALUE_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';

    /**
     * Whether a scope id has the shape SearchKit allows (SearchScope::ID_PATTERN).
     *
     * @param string|null $scope Scope id, or null
     *
     * @return bool True for a well-formed id; false for null or anything else
     */
    public static function isValid(?string $scope): bool
    {
        return $scope !== null && preg_match(SearchScope::ID_PATTERN, $scope) === 1;
    }

    /**
     * The value of a `prefix:value` scope (e.g. `project:42` with prefix `project`).
     *
     * @param string|null $scope Scope id, or null
     * @param string $prefix Expected prefix, without the colon
     *
     * @return string|null The value, or null when the scope is missing, malformed or has another prefix
     */
    public static function value(?string $scope, string $prefix): ?string
    {
        if (!self::isValid($scope) || $prefix === '') {
            return null;
        }
        $head = $prefix . ':';
        if (substr((string) $scope, 0, strlen($head)) !== $head) {
            return null;
        }
        $value = substr((string) $scope, strlen($head));

        return preg_match(self::VALUE_PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * The positive integer of a `prefix:<digits>` scope.
     *
     * @param string|null $scope Scope id, or null
     * @param string $prefix Expected prefix, without the colon
     *
     * @return int|null The id (1 to PHP_INT_MAX), or null
     */
    public static function positiveInt(?string $scope, string $prefix): ?int
    {
        $value = self::value($scope, $prefix);
        if ($value === null || !ctype_digit($value) || strlen($value) > 18 || $value[0] === '0') {
            return null;
        }

        return (int) $value;
    }
}
