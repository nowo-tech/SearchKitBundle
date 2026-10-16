<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Model;

use InvalidArgumentException;

/**
 * A search scope (id sent to the endpoint, label shown on the chip).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchScope
{
    /** Allowed id characters (the endpoint ignores any other `scope` value). */
    public const string ID_PATTERN = '/^[A-Za-z0-9._:-]{1,128}$/';

    /**
     * @param string $id Opaque id (UUID, slug, …) matching ID_PATTERN
     * @param string $label Text of the chip (escaped by Twig)
     *
     * @throws InvalidArgumentException When the id does not match ID_PATTERN
     */
    public function __construct(
        public string $id,
        public string $label,
    ) {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException('A search scope id may only contain letters, digits, ".", "_", ":" and "-" (1-128 characters).');
        }
    }

    /**
     * Scope as the template and the browser see it.
     *
     * @return array{id: string, label: string} The scope
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label];
    }
}
