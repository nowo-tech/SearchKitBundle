<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Provider\Entity;

use function array_key_exists;

/**
 * Display data of one render (1.10.0): EntitySearchProvider creates a new context for every search page and every
 * recent it resolves, passes it to EntitySearchDefinition::prepareItems() and then to toPreparedItem() for each row
 * shown, and drops it when the call returns. It is never a service, never shared between calls and never kept by
 * SearchKit, so what a definition puts in it (titles loaded in one query for the shown ids, say) cannot reach another
 * viewer or another request in FrankenPHP worker mode with kernel reset off.
 *
 * Values are keyed by a name the definition chooses (e.g. `titles`); keep them to what toPreparedItem() needs.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class SearchItemContext
{
    /** @var array<string, mixed> */
    private array $values = [];

    /**
     * Stores a value for the rows of this render (replaces an earlier value of the same key).
     *
     * @param string $key The name the definition chooses
     * @param mixed $value The value
     */
    public function set(string $key, mixed $value): void
    {
        // @igor-ignore - per-render object, created for one search page or one recent and never reused (not a service)
        $this->values[$key] = $value;
    }

    /**
     * Whether a value is stored under the key (null values included).
     *
     * @param string $key The name
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * The value stored under the key, or `$default`.
     *
     * @param string $key The name
     * @param mixed $default Returned when nothing is stored under the key
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }
}
