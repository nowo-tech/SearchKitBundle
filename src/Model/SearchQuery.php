<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Model;

use InvalidArgumentException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * What a provider searches for: the trimmed text, the optional scope id, the current user's identifier and the row
 * limit.
 *
 * Providers read the user as `$userIdentifier` (1.7.0). The Symfony security user in `$user` is deprecated and goes in
 * 2.0 (REQ-ARCH-001 rule 3): the bundle's endpoint still fills it, but a search run through
 * SearchEngineInterface::searchFor() (or built with forUserIdentifier()) leaves it null.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchQuery
{
    /** The signed-in user's identifier: providers check permissions against it. */
    public string $userIdentifier;

    /**
     * @param string $text Trimmed query, at most `max_length` characters; at least `min_length` unless $belowMinLength
     * @param UserInterface|null $user Deprecated since 1.7.0, removed in 2.0: the signed-in Symfony user, or null when
     *                                 the search runs by identifier. Read $userIdentifier instead.
     * @param int $limit Rows per group (`per_group`)
     * @param string|null $scope Scope id from the chip (SearchScope::ID_PATTERN), or null when unscoped. It is
     *                           client input: providers must treat an unknown or forbidden id as "no rows".
     * @param bool $belowMinLength The text is shorter than `min_length` and matched `min_length_exempt_patterns`: only
     *                             MinLengthExemptSearchProviderInterface providers see such a query; run the exact
     *                             lookup it asks for, not a `LIKE` scan
     * @param string|null $userIdentifier The user's identifier; defaults to `$user->getUserIdentifier()`
     *
     * @throws InvalidArgumentException When neither a user nor an identifier is given, they disagree, or the identifier is empty
     */
    public function __construct(
        public string $text,
        /** @deprecated since 1.7.0, removed in 2.0: read $userIdentifier */
        public ?UserInterface $user,
        public int $limit,
        public ?string $scope = null,
        public bool $belowMinLength = false,
        ?string $userIdentifier = null,
    ) {
        $fromUser = $user?->getUserIdentifier();
        if ($userIdentifier === null && $fromUser === null) {
            throw new InvalidArgumentException('A search query needs the user identifier.');
        }
        if ($userIdentifier !== null && $fromUser !== null && $userIdentifier !== $fromUser) {
            throw new InvalidArgumentException('The user identifier of a search query must be the identifier of its user.');
        }
        $this->userIdentifier = $userIdentifier ?? (string) $fromUser;
        if ($this->userIdentifier === '') {
            throw new InvalidArgumentException('The user identifier of a search query cannot be empty.');
        }
    }

    /**
     * A query for one user, by identifier only (no Symfony user: $user is null).
     *
     * @param string $userIdentifier The user the providers check permissions for
     * @param string $text Trimmed query
     * @param int $limit Rows per group
     * @param string|null $scope Scope id, or null
     * @param bool $belowMinLength See the constructor
     *
     * @return self The query
     */
    public static function forUserIdentifier(string $userIdentifier, string $text, int $limit, ?string $scope = null, bool $belowMinLength = false): self
    {
        return new self($text, null, $limit, $scope, $belowMinLength, $userIdentifier);
    }

    /**
     * Whether the search is limited to a scope.
     *
     * @return bool True when a scope id is set
     */
    public function isScoped(): bool
    {
        return $this->scope !== null;
    }

    /**
     * Case-insensitive "contains" check against the query, for providers that filter in PHP.
     *
     * @param string ...$haystacks Texts to look in
     *
     * @return bool True when one of them contains the query
     */
    public function matches(string ...$haystacks): bool
    {
        $needle = mb_strtolower($this->text);
        foreach ($haystacks as $haystack) {
            if (str_contains(mb_strtolower($haystack), $needle)) {
                return true;
            }
        }

        return false;
    }
}
