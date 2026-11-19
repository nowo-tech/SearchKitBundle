<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Model;

use InvalidArgumentException;

use function preg_match;
use function strlen;

/**
 * What a search row shows (1.9.0): a subject type (e.g. `task`, `crm_company`) and its id, as given to the host by the
 * SearchKitEvents::ITEM_VISIBILITY event. Immutable.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchSubject
{
    /** Allowed type characters. */
    public const string TYPE_PATTERN = '/^[a-z][a-z0-9_.-]{0,63}$/';

    /** Longest id. */
    public const int MAX_ID_LENGTH = 255;

    /**
     * @param string $type Subject type (TYPE_PATTERN)
     * @param string $id Subject id (1 to MAX_ID_LENGTH characters)
     *
     * @throws InvalidArgumentException When the type or the id is not valid
     */
    public function __construct(
        public string $type,
        public string $id,
    ) {
        if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
            throw new InvalidArgumentException('A search subject type must be lower-case letters, digits, "_", "." or "-" (letter first, at most 64).');
        }
        if ($id === '' || strlen($id) > self::MAX_ID_LENGTH) {
            throw new InvalidArgumentException('A search subject id must be 1 to 255 bytes long.');
        }
    }

    /**
     * The key of the subject in a batch (`type#id`).
     */
    public function key(): string
    {
        return $this->type . '#' . $this->id;
    }
}
