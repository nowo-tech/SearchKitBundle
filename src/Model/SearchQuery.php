<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Model;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * What a provider searches for: the trimmed text, the optional scope id, the current user and the row limit.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchQuery
{
    /**
     * @param string $text Trimmed query, at least `min_length` and at most `max_length` characters
     * @param UserInterface $user The signed-in user (providers check permissions against it)
     * @param int $limit Rows per group (`per_group`)
     * @param string|null $scope Scope id from the chip (SearchScope::ID_PATTERN), or null when unscoped. It is
     *                           client input: providers must treat an unknown or forbidden id as "no rows".
     */
    public function __construct(
        public string $text,
        public UserInterface $user,
        public int $limit,
        public ?string $scope = null,
    ) {
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
