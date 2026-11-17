<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Model;

use InvalidArgumentException;

/**
 * One typed filter of a query (`key:value`, `key:"quoted value"`, `-key:value`), parsed by the engine from the text
 * when its key is listed in `nowo_search_kit.filters.keys` (1.8.0).
 *
 * The value is client input, never trusted: a provider resolves it **for the viewer** (e.g. `assignee:me`, a project
 * slug the viewer may open) and treats an unknown or unauthorised value exactly like a value with no match — no rows,
 * no error, no different count — so a filter cannot be used to probe for existence.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchFilter
{
    /** Allowed key characters (lower-case letter first, at most 32 characters). */
    public const string KEY_PATTERN = '/^[a-z][a-z0-9_-]{0,31}$/';

    /** Longest value kept (longer values are cut). */
    public const int MAX_VALUE_LENGTH = 100;

    /**
     * @param string $key Filter key (KEY_PATTERN)
     * @param string $value Unquoted value (1 to MAX_VALUE_LENGTH characters)
     * @param bool $negated True for `-key:value` (exclude the matches)
     *
     * @throws InvalidArgumentException When the key does not match KEY_PATTERN or the value is empty or too long
     */
    public function __construct(
        public string $key,
        public string $value,
        public bool $negated = false,
    ) {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException('A search filter key must match ' . self::KEY_PATTERN . '.');
        }
        if ($value === '' || mb_strlen($value) > self::MAX_VALUE_LENGTH) {
            throw new InvalidArgumentException('A search filter value must have 1 to ' . self::MAX_VALUE_LENGTH . ' characters.');
        }
    }

    /**
     * The filter as typed back into the field (`-key:"value with spaces"`).
     *
     * @return string The token
     */
    public function toToken(): string
    {
        $value = preg_match('/[\s"]/u', $this->value) === 1 ? '"' . str_replace('"', '', $this->value) . '"' : $this->value;

        return ($this->negated ? '-' : '') . $this->key . ':' . $value;
    }

    /**
     * Filter as sent to the browser (chips).
     *
     * @return array{key: string, value: string, negated: bool, token: string} The filter
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'value' => $this->value, 'negated' => $this->negated, 'token' => $this->toToken()];
    }
}
