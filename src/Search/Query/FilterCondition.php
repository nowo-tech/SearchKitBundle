<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search\Query;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder as DbalQueryBuilder;
use Doctrine\ORM\QueryBuilder as OrmQueryBuilder;
use InvalidArgumentException;

use function preg_match;
use function sprintf;
use function str_starts_with;

/**
 * SQL / DQL conditions of one typed filter (1.13.0), for EntitySearchDefinition::applyFilters(): the value the
 * definition resolved for the viewer is always **bound** (never written into the query), and a negated filter
 * (`-key:value`) keeps rows whose column is NULL — `column <> :value` alone would drop them. Stateless.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class FilterCondition
{
    /** A parameter name: a letter, then letters, digits and `_` (never `nowo_search_*`, SearchKit's own). */
    private const string PARAMETER_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,63}\z/';

    /** SearchKit's parameter prefix (EntitySearchProvider::PARAMETER_PREFIX, repeated: no dependency on the provider). */
    private const string RESERVED_PREFIX = 'nowo_search_';

    /**
     * Adds `field = :parameter`, or `(field IS NULL OR field <> :parameter)` when negated, and binds the value.
     *
     * @param DbalQueryBuilder|OrmQueryBuilder $queryBuilder The definition's query
     * @param string $field `column` or `alias.column` (LikePattern::FIELD_PATTERN)
     * @param bool|int|string $value The value resolved for the viewer (never the raw filter text of an unresolved value)
     * @param bool $negated True for `-key:value` (SearchFilter::$negated)
     * @param string $parameter Parameter name, without the colon (unique in the query)
     * @param int|ParameterType|string|null $type Parameter type (e.g. ParameterType::BOOLEAN, `'boolean'`; DBAL 3 or 4), or null: the default
     *
     * @throws InvalidArgumentException When the field or the parameter name is not safe
     */
    public static function equals(DbalQueryBuilder|OrmQueryBuilder $queryBuilder, string $field, bool|int|string $value, bool $negated, string $parameter, ParameterType|int|string|null $type = null): void
    {
        if (!LikePattern::isField($field)) {
            throw new InvalidArgumentException(sprintf('A filter field must be "column" or "alias.column", got "%s".', $field));
        }
        if (preg_match(self::PARAMETER_PATTERN, $parameter) !== 1 || str_starts_with($parameter, self::RESERVED_PREFIX)) {
            throw new InvalidArgumentException('A filter parameter name must be a letter followed by up to 63 letters, digits or "_", and not start with "' . self::RESERVED_PREFIX . '".');
        }
        $queryBuilder->andWhere($negated
            ? sprintf('(%1$s IS NULL OR %1$s <> :%2$s)', $field, $parameter)
            : sprintf('%s = :%s', $field, $parameter));
        if ($type !== null) {
            $queryBuilder->setParameter($parameter, $value, $type);

            return;
        }
        $queryBuilder->setParameter($parameter, $value);
    }

    /**
     * Adds a condition no row meets: for a value that resolves to nothing the viewer may see (unknown, another
     * tenant's, forbidden) in a positive filter, so the query still runs — the same statements as a value with no
     * match — and returns no row.
     *
     * @param DbalQueryBuilder|OrmQueryBuilder $queryBuilder The definition's query
     */
    public static function matchNothing(DbalQueryBuilder|OrmQueryBuilder $queryBuilder): void
    {
        $queryBuilder->andWhere('1 = 0');
    }
}
