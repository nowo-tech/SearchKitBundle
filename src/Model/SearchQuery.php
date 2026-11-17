<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Model;

use InvalidArgumentException;
use Nowo\SearchKitBundle\Search\Text\FoldedText;
use Nowo\SearchKitBundle\Search\Text\TextMatcher;
use Nowo\SearchKitBundle\Search\Text\TextNormalizer;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_values;
use function count;

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
    /** scoreFolded() mode: the exact tiers only. */
    public const int MATCH_EXACT = 1;

    /** scoreFolded() mode: the typo tier only. */
    public const int MATCH_FUZZY = 2;

    /** scoreFolded() mode: exact tiers, then typos for a term with no exact match. */
    public const int MATCH_ALL = 3;

    /** Evaluation cost of one fuzzy comparison (edit distances) relative to an exact one, for cost budgets. */
    public const int FUZZY_COST = 16;

    /** The signed-in user's identifier: providers check permissions against it. */
    public string $userIdentifier;

    /** @var list<SearchFilter> Typed filters (1.8.0), already removed from */
    public array $filters;

    /** @var list<SearchTerm> Synonym alternatives (1.8.0), without the typed text (see terms()) */
    private array $alternatives;

    /** @var list<array{0: FoldedText, 1: float, 2: bool}> Every term folded once: needle, weight, fuzzy (audit SP-M1) */
    private array $needles;

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
        $needles            = [];
        foreach ($this->terms() as $term) {
            $weight    = !$term->synonym ? 1.0 : ($term->locale !== null && $term->locale === $locale ? 0.85 : 0.75);
            $needles[] = [FoldedText::needle($term->text), $weight, $fuzzy && !$term->synonym];
        }
        $this->needles = $needles;
        $fromUser      = $user?->getUserIdentifier();
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
     * match in the viewer's language ranks first. Fuzzy matching ($fuzzy) applies to the typed text only, never to a
     * synonym. See TextMatcher for the tiers.
     *
     * @param string ...$haystacks Texts to look in
     *
     * @return float|null The score in ]0, 1], or null when nothing matches
     */
    public function score(string ...$haystacks): ?float
    {
        return $this->scoreFolded(array_values(array_map(FoldedText::haystack(...), $haystacks)));
    }

    /**
     * score() on haystacks already folded (FoldedText::haystack()): fold a candidate's texts once, compare with every
     * term (each folded once in the constructor). A term none of whose words occurs in any of the texts is rejected
     * with one substring test; a text that cannot beat the best score is not compared.
     *
     * @param list<FoldedText> $haystacks Folded texts
     * @param int $evaluations Incremented by the comparisons made (a fuzzy one counts FUZZY_COST), for cost budgets
     * @param int $mode MATCH_EXACT (exact tiers only), MATCH_FUZZY (typo tier only) or MATCH_ALL (exact tiers, then the
     *                  typo tier for a term with no exact match)
     *
     * @return float|null The score in ]0, 1], or null when nothing matches
     */
    public function scoreFolded(array $haystacks, int &$evaluations = 0, int $mode = self::MATCH_ALL): ?float
    {
        $joined = implode("\n", array_map(static fn (FoldedText $haystack): string => $haystack->text, $haystacks));
        $best   = null;
        foreach ($this->needles as [$needle, $weight, $fuzzy]) {
            if ($best !== null && $best >= $weight) {
                continue;
            }
            $found = null;
            // Exact tiers first, on every text; one substring test rejects a term none of whose words occurs.
            if (($mode & self::MATCH_EXACT) !== 0 && $this->occurs($needle, $joined)) {
                foreach ($haystacks as $haystack) {
                    ++$evaluations;
                    $score = TextMatcher::scoreFolded($needle, $haystack);
                    $found = $score !== null && ($found === null || $score > $found) ? $score : $found;
                }
            }
            // Typos (typed text only, never a synonym) only when no exact tier matched; each costs FUZZY_COST.
            if ($found === null && $fuzzy && ($mode & self::MATCH_FUZZY) !== 0) {
                foreach ($haystacks as $haystack) {
                    $evaluations += self::FUZZY_COST;
                    $score = TextMatcher::fuzzyFolded($needle, $haystack);
                    $found = $score !== null && ($found === null || $score > $found) ? $score : $found;
                }
            }
            if ($found !== null && ($best === null || $found * $weight > $best)) {
                $best = $found * $weight;
            }
        }

        return $best;
    }

    /**
     * The typo tier of score() (what scoreFolded() gives in MATCH_FUZZY mode) at a cost proportional to the work done
     * (1.8.1, audit SP3-L1): the distinct words of the texts are taken once (FoldedText::wordsOf()) and each fuzzy term
     * is compared with them. For a one-word term that is the score itself (the closest word wins wherever it is); a
     * term of several words, whose words must all come close in one text, is then compared text by text, only when the
     * merged words came close. A term that cannot beat the best score so far is not compared.
     *
     * @param list<string> $texts Raw texts of a candidate
     * @param int $evaluations Incremented by the work done, for cost budgets: one per text (folding), one per distinct
     *                         word and fuzzy term (at most two edit distances per term word), FUZZY_COST per text
     *                         compared one by one
     *
     * @return float|null The typo score in ]0, TextMatcher::FUZZY_MAX], or null when no typo matches
     */
    public function scoreTypos(array $texts, int &$evaluations = 0): ?float
    {
        $words  = null;
        $folded = null;
        $best   = null;
        foreach ($this->needles as [$needle, $weight, $fuzzy]) {
            if (!$fuzzy || ($best !== null && $best >= $weight * TextMatcher::FUZZY_MAX)) {
                continue;
            }
            if ($words === null) {
                $evaluations += count($texts);
                $words = FoldedText::wordsOf($texts);
            }
            $evaluations += count($words);
            $found = TextMatcher::fuzzyWords($needle, $words);
            if ($found !== null && count($needle->words) > 1 && count($texts) > 1) {
                $folded ??= array_map(FoldedText::haystack(...), $texts);
                $found = null;
                foreach ($folded as $haystack) {
                    $evaluations += self::FUZZY_COST;
                    $score = TextMatcher::fuzzyFolded($needle, $haystack);
                    $found = $score !== null && ($found === null || $score > $found) ? $score : $found;
                }
            }
            if ($found !== null && ($best === null || $found * $weight > $best)) {
                $best = $found * $weight;
            }
        }

        return $best;
    }

    /**
     * Whether some term could match one of these texts exactly: every word of a term occurs in the folded, joined texts
     * (a necessary condition of every exact tier). Lets a caller skip folding a candidate's texts one by one.
     *
     * @param string $joined The candidate's texts folded together (TextNormalizer::fold(), any separator)
     *
     * @return bool False when no exact tier can match
     */
    public function mayMatchExactly(string $joined): bool
    {
        foreach ($this->needles as [$needle]) {
            if ($this->occurs($needle, $joined)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a term tolerates typos (the typed text with `matching.fuzzy`).
     *
     * @return bool True when the typo tier can run
     */
    public function hasFuzzyTerm(): bool
    {
        foreach ($this->needles as [, , $fuzzy]) {
            if ($fuzzy) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the typo tier may match one of a candidate's texts: some fuzzy term (the typed text with
     * `matching.fuzzy`) has every word within its allowed edits of a word of the folded, joined texts. False without a
     * fuzzy term. Lets a caller skip a candidate without folding and comparing its texts one by one.
     *
     * @param string $joined The candidate's texts folded together (TextNormalizer::fold())
     * @param int $evaluations Incremented by the number of candidate words per fuzzy term checked, for cost budgets
     *
     * @return bool True when the typo tier can match
     */
    public function mayMatchFuzzily(string $joined, int &$evaluations = 0): bool
    {
        $words = null;
        foreach ($this->needles as [$needle, , $fuzzy]) {
            if (!$fuzzy) {
                continue;
            }
            $words ??= array_values(array_unique(TextNormalizer::words($joined, 2048)));
            // Charged per candidate word: each one may cost two edit distances.
            $evaluations += count($words);
            if (TextMatcher::mayMatchFuzzily($needle, $words)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether every word of a needle occurs somewhere in the joined texts (a necessary condition of every exact tier).
     */
    private function occurs(FoldedText $needle, string $joined): bool
    {
        if ($needle->words === []) {
            return str_contains($joined, $needle->text);
        }
        foreach ($needle->words as $word) {
            if (!str_contains($joined, $word)) {
                return false;
            }
        }

        return true;
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
