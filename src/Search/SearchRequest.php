<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search;

use InvalidArgumentException;
use Nowo\SearchKitBundle\Model\SearchContext;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * One search to run through SearchEngine::execute() (1.8.0): the raw text, the viewer and where the search box is.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchRequest
{
    public string $userIdentifier;

    /**
     * @param string $text Raw query (the engine trims it, reads the `>` prefix and the typed filters)
     * @param UserInterface|null $user The Symfony user (endpoint), or null for a search by identifier
     * @param string|null $userIdentifier The viewer (defaults to the user's identifier)
     * @param string|null $scope Scope id (client input), or null
     * @param string|null $locale UI locale, or null
     * @param SearchContext|null $context The page the box is on (for the action providers), or null
     * @param int|null $limit Rows per group (null: `per_group`)
     *
     * @throws InvalidArgumentException When there is neither a user nor a non-empty identifier
     */
    public function __construct(
        public string $text,
        public ?UserInterface $user = null,
        ?string $userIdentifier = null,
        public ?string $scope = null,
        public ?string $locale = null,
        public ?SearchContext $context = null,
        public ?int $limit = null,
    ) {
        $identifier = $userIdentifier ?? $user?->getUserIdentifier() ?? '';
        if ($identifier === '') {
            throw new InvalidArgumentException('A search request needs a user or a non-empty user identifier.');
        }
        $this->userIdentifier = $identifier;
    }
}
