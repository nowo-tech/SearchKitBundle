<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Model;

use InvalidArgumentException;
use Nowo\SearchKitBundle\Search\Text\TextMatcher;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_values;

/**
 * What a provider searches for: the trimmed text, the optional scope id, the current user's identifier and the row
 * limit.
 *
 * Providers read the user as `$userIdentifier` (1.7.0). The Symfony security user in `$user` is deprecated and goes in
 * 2.0 (REQ-ARCH-001 rule 3): the bundle's endpoint still fills it, but a search run through
 * SearchEngineInterface::searchFor() (or built with forUserIdentifier()) leaves it null.
 *
 * 1.8.0 adds the typed filters ($filters, parsed out of $text), the UI locale, the synonym alternatives (terms()) and
 * the fuzzy flag. matches() and score() fold case and accents and try every alternative.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchQuery
{
    /** The signed-in user's identifier: providers check permissions against it. */
    public string $userIdentifier;

    /** @var list<SearchFilter> Typed filters (1.8.0), already removed from $text */
    public array $filters;

    /** @var list<SearchTerm> Synonym alternatives (1.8.0), without the typed text (see terms()) */
    private array $alternatives;

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
     * @param list<SearchFilter> $filters Typed filters (1.8.0). Client input: resolve each value for the viewer and
     *                                    treat an unknown or forbidden value as "no rows"
     * @param string|null $locale UI locale of the viewer (1.8.0), or null
     * @param list<SearchTerm> $terms Synonym alternatives of the text (1.8.0; filled by the engine when synonyms are on)
     * @param bool $fuzzy matches() / score() tolerate typos (1.8.0, `matching.fuzzy`)
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
        array $filters = [],
        public ?string $locale = null,
        array $terms = [],
        public bool $fuzzy = false,
    ) {
        $this->filters      = array_values($filters);
        $this->alternatives = array_values($terms);
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
     * Match check against the query, for providers that filter in PHP: case and accent insensitive (1.8.0), tries
     * every alternative of terms(), and tolerates typos when $fuzzy is on.
     *
     * @param string ...$haystacks Texts to look in
     *
     * @return bool True when one of them matches
     */
    public function matches(string ...$haystacks): bool
    {
        return $this->score(...$haystacks) !== null;
    }

    /**
     * Best relevance of the haystacks for this query (1.8.0), for SearchItem::$score.
     *
     * The typed text counts in full, a synonym in the UI locale 0.85 of it, a synonym in another locale 0.75 — so a
     * match in the viewer's language ranks first. See TextMatcher for the tiers.
     *
     * @param string ...$haystacks Texts to look in
     *
     * @return float|null The score in ]0, 1], or null when nothing matches
     */
    public function score(string ...$haystacks): ?float
    {
        $best = null;
        foreach ($this->terms() as $term) {
            $weight = !$term->synonym ? 1.0 : ($term->locale !== null && $term->locale === $this->locale ? 0.85 : 0.75);
            foreach ($haystacks as $haystack) {
                $score = TextMatcher::score($term->text, $haystack, $this->fuzzy);
                if ($score !== null && ($best === null || $score * $weight > $best)) {
                    $best = $score * $weight;
                }
            }
        }

        return $best;
    }

    /**
     * The text and its synonym alternatives (1.8.0): the typed text first (locale null), then the alternatives the
     * SearchSynonymProviderInterface services returned, those in the UI locale before the others.
     *
     * @return list<SearchTerm> At least the typed text (when it is not empty)
     */
    public function terms(): array
    {
        return $this->text === '' ? $this->alternatives : [new SearchTerm($this->text), ...$this->alternatives];
    }

    /**
     * The filters of one key (1.8.0).
     *
     * @param string $key Filter key
     *
     * @return list<SearchFilter> The filters with that key, in typed order
     */
    public function filtersOf(string $key): array
    {
        return array_values(array_filter($this->filters, static fn (SearchFilter $filter): bool => $filter->key === $key));
    }

    /**
     * Whether the query carries typed filters (1.8.0).
     *
     * @return bool True when $filters is not empty
     */
    public function hasFilters(): bool
    {
        return $this->filters !== [];
    }
}
