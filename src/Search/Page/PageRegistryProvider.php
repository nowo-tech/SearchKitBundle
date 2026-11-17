<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Search\Page;

use Nowo\SearchKitBundle\Contract\IdentifierAwareSearchProviderInterface;
use Nowo\SearchKitBundle\Contract\SearchPageSourceInterface;
use Nowo\SearchKitBundle\Contract\SearchReferenceResolverInterface;
use Nowo\SearchKitBundle\Model\SearchAccessRequest;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchPage;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Search\Access\SearchAccess;
use Nowo\SearchKitBundle\Search\Text\FoldedText;
use Nowo\SearchKitBundle\Search\Text\TextMatcher;
use Nowo\SearchKitBundle\Search\Text\TextNormalizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function array_key_exists;
use function array_slice;
use function count;
use function in_array;
use function is_string;

use const PREG_SPLIT_NO_EMPTY;

/**
 * The page registry (1.8.0): `nowo_search_kit.pages` plus the SearchPageSourceInterface entries, in the Pages group.
 *
 * For every candidate, in this order: scope rule (`none` entries only while unscoped, `required` entries only while
 * scoped), URL generation, **access check (deny by default)**, and only then matching — on the label, the breadcrumb
 * and the keywords (UI locale first, then the labels and keywords of the other `page_registry.locales`; keywords are
 * never shown). Bounded (audit SP-M1): 32 keywords per entry and locale, 64 of the other locales after deduplication,
 * every text folded once, and at most MAX_EVALUATIONS term × text comparisons per search. Exact tiers first over every
 * visible entry, then typos tier by tier (1.8.1, audit SP3-L1). A denied entry is never matched, counted, ranked or
 * rendered, and the group total is the number of visible matches (exact).
 *
 * Also resolves recent references to registry pages (SearchReferenceResolverInterface). Holds no state.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class PageRegistryProvider implements IdentifierAwareSearchProviderInterface, SearchReferenceResolverInterface
{
    public const string KEY = 'pages';

    /** Most keywords read per entry and per locale (like SearchAction::MAX_KEYWORDS). */
    public const int MAX_KEYWORDS = 32;

    /** Most keywords of the other locales kept per entry, after deduplication. */
    public const int MAX_OTHER_KEYWORDS = 64;

    /**
     * Ceiling of term × text comparisons per search (audit SP-M1). Entries are scored in order until it is reached; the
     * rest are not listed (fewer rows, never more) and a notice is logged without the query.
     */
    public const int MAX_EVALUATIONS = 100_000;

    /**
     * Part of MAX_EVALUATIONS the typo pass may spend (audit SPd-I1): fuzzy matching is the costliest tier, and it runs
     * only after every exact match is in, tier by tier (audit SP3-L1).
     */
    public const int MAX_FUZZY_EVALUATIONS = 40_000;

    /** Weight of each tier of texts: label, breadcrumb, UI-locale keywords, other locales (keywords and labels). */
    private const array TIER_WEIGHTS = [1.0, 0.9, 0.95 * 0.9, 0.85 * 0.9];

    /** @var list<SearchPage> */
    private array $pages;

    /**
     * @param list<array{id: string, label: string, translation_domain: string|null, route: string, route_parameters: array<string, scalar|null>, trail: list<string>, icon: string|null, keywords: string|null, scope: string, attribute: string|null, subject: string|null, scope_parameter: string|null}> $pages Normalised `nowo_search_kit.pages` rows
     * @param iterable<SearchPageSourceInterface> $sources Tagged page sources
     * @param SearchAccess $access Access checkers (deny by default)
     * @param UrlGeneratorInterface $urls Builds the page URLs
     * @param TranslatorInterface $translator Translates labels, breadcrumbs and keywords
     * @param list<string> $locales Locales whose keywords also match (cross-locale)
     * @param int $maxCandidates Most entries read per search (configured first, then the sources)
     * @param string $defaultIcon UX Icons name of an entry without an icon
     * @param LoggerInterface|null $logger Failing sources are reported here
     */
    public function __construct(
        array $pages,
        private iterable $sources,
        private SearchAccess $access,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private array $locales = ['en', 'es', 'it', 'fr', 'pt', 'de', 'nl'],
        private int $maxCandidates = 500,
        private string $defaultIcon = 'tabler:file',
        private ?LoggerInterface $logger = null,
    ) {
        /* @var list<array{id: string, label: string, translation_domain: string|null, route: string, route_parameters: array<string, scalar|null>, trail: list<string>, icon: string|null, keywords: string|null, scope: string, attribute: string|null, subject: string|null, scope_parameter: string|null}> $pages */
        $this->pages = array_map(SearchPage::fromConfig(...), $pages);
    }

    /**
     * Searches the visible registry entries.
     *
     * @param SearchQuery $query The query
     *
     * @return SearchGroup|null The Pages rows (exact total), or null when none matches
     */
    public function search(SearchQuery $query): ?SearchGroup
    {
        $exact       = [];
        $pending     = [];
        $evaluations = 0;
        $stopped     = false;
        // Pass 1 (exact tiers) over every visible candidate: an exact match is never cut by the cost of typos.
        foreach ($this->candidates($query) as $page) {
            $url = $this->visibleUrl($page, $query->userIdentifier, $query->scope, SearchAccessRequest::KIND_PAGE);
            if ($url === null || array_key_exists($url, $exact) || array_key_exists($url, $pending)) {
                continue;
            }
            if ($evaluations >= self::MAX_EVALUATIONS) {
                $stopped = $this->ceilingReached($stopped);

                break;
            }
            $texts = $this->texts($page, $query->locale);
            $score = $query->mayMatchExactly($texts[4]) ? $this->score($texts, $query, $evaluations) : null;
            if ($score !== null) {
                $exact[$url] = [$page, $texts, $score];
            } elseif ($query->hasFuzzyTerm()) {
                $pending[$url] = [$page, $texts, null];
            }
        }
        $typos = $query->hasFuzzyTerm() ? $this->typos($query, $exact, $pending, $evaluations, $stopped) : [];
        $found = [];
        foreach ([$exact, $typos] as $entries) {
            foreach ($entries as $url => [$page, , $score]) {
                if ($score !== null) {
                    $found[] = $this->item($page, $url, $query->locale, $score);
                }
            }
        }
        if ($found === []) {
            return null;
        }

        return new SearchGroup(self::KEY, $this->translator->trans('search.group.pages', [], 'NowoSearchKitBundle'), $found, count($found), null, null, true);
    }

    /**
     * Pass 2 (typos, audit SP3-L1), on the remaining budget (at most MAX_FUZZY_EVALUATIONS): tier by tier across every
     * entry without an exact match — all the labels first, then the breadcrumbs, the UI-locale keywords and the other
     * locales — so that a cut drops the least relevant texts, not the last entries in configuration order. A tier that
     * cannot beat an entry's best score (its weight × TextMatcher::FUZZY_MAX) is skipped, which leaves the ranking
     * equal to comparing every tier. Each tier is charged what SearchQuery::scoreTypos() does (texts, distinct words).
     *
     * Audit SP3-I1: an exact entry scoring below what a label typo can give also has its label compared for typos (as
     * comparing every tier would), and keeps the better score.
     *
     * @param array<string, array{0: SearchPage, 1: array{0: list<string>, 1: list<string>, 2: list<string>, 3: list<string>, 4: string}, 2: float|null}> $exact Updated in place (SP3-I1)
     * @param array<string, array{0: SearchPage, 1: array{0: list<string>, 1: list<string>, 2: list<string>, 3: list<string>, 4: string}, 2: float|null}> $pending
     *
     * @return array<string, array{0: SearchPage, 1: array{0: list<string>, 1: list<string>, 2: list<string>, 3: list<string>, 4: string}, 2: float|null}> The pending entries with their typo scores, in configuration order
     */
    private function typos(SearchQuery $query, array &$exact, array $pending, int &$evaluations, bool $stopped): array
    {
        $ceiling = min(self::MAX_EVALUATIONS, $evaluations + self::MAX_FUZZY_EVALUATIONS);
        foreach (self::TIER_WEIGHTS as $tier => $weight) {
            // The label tier also re-scores the exact entries a label typo could beat (SP3-I1), after the others.
            if (!$this->typoTier($query, $pending, $tier, $weight, $evaluations, $ceiling)
                || ($tier === 0 && !$this->typoTier($query, $exact, $tier, $weight, $evaluations, $ceiling))) {
                $this->ceilingReached($stopped);

                break;
            }
        }

        return $pending;
    }

    /**
     * One tier of the typo pass over a list of entries, their best score updated in place.
     *
     * @param array<string, array{0: SearchPage, 1: array{0: list<string>, 1: list<string>, 2: list<string>, 3: list<string>, 4: string}, 2: float|null}> $entries
     * @param int<0, 3> $tier Index in TIER_WEIGHTS (and in the texts)
     *
     * @return bool False when the ceiling stopped it
     */
    private function typoTier(SearchQuery $query, array &$entries, int $tier, float $weight, int &$evaluations, int $ceiling): bool
    {
        $bound = $weight * TextMatcher::FUZZY_MAX;
        foreach ($entries as $url => [, $texts, $best]) {
            if ($texts[$tier] === [] || ($best !== null && $best >= $bound)) {
                continue;
            }
            if ($evaluations >= $ceiling) {
                return false;
            }
            $score = $query->scoreTypos($texts[$tier], $evaluations);
            if ($score !== null && ($best === null || $score * $weight > $best)) {
                $entries[$url][2] = $score * $weight;
            }
        }

        return true;
    }

    /**
     * Logs the ceiling notice once per search (without the query).
     *
     * @return bool True: the notice is logged
     */
    private function ceilingReached(bool $logged): bool
    {
        if (!$logged) {
            $this->logger?->notice('Search page registry stopped at its evaluation ceiling ({ceiling}).', ['ceiling' => self::MAX_EVALUATIONS]);
        }

        return true;
    }

    /**
     * The row of a recent reference to a configured page (same route and URL), when the viewer may open it.
     */
    public function resolve(SearchReference $reference, string $userIdentifier, ?string $locale): ?SearchItem
    {
        foreach ($this->pages as $page) {
            if ($page->route !== $reference->route) {
                continue;
            }
            $scope = null;
            if ($page->scope === SearchPage::SCOPE_REQUIRED) {
                $value = $page->scopeParameter !== null ? ($reference->routeParameters[$page->scopeParameter] ?? null) : null;
                if (!is_string($value) || $value === '') {
                    continue;
                }
                $scope = $value;
            }
            if ($this->visibleUrl($page, $userIdentifier, $scope, SearchAccessRequest::KIND_RECENT) === $reference->url) {
                return $this->item($page, $reference->url, $locale, null);
            }
        }

        return null;
    }

    /**
     * @return iterable<SearchPage>
     */
    private function candidates(SearchQuery $query): iterable
    {
        $read = 0;
        foreach ($this->pages as $page) {
            if ($read++ >= $this->maxCandidates) {
                return;
            }
            yield $page;
        }
        foreach ($this->sources as $source) {
            try {
                foreach ($source->pages($query) as $page) {
                    if ($read++ >= $this->maxCandidates) {
                        return;
                    }
                    yield $page;
                }
            } catch (Throwable $exception) {
                $this->logger?->error('Search page source {source} failed: {exception}.', [
                    'source'    => $source::class,
                    'exception' => $exception::class,
                ]);
            }
        }
    }

    /**
     * The URL of an entry when its scope rule fits and the viewer may open it; null otherwise.
     */
    private function visibleUrl(SearchPage $page, string $userIdentifier, ?string $scope, string $kind): ?string
    {
        $required = $page->scope === SearchPage::SCOPE_REQUIRED;
        if ($required !== ($scope !== null)) {
            return null;
        }
        $parameters = $page->routeParameters;
        if ($required && $page->scopeParameter !== null) {
            $parameters[$page->scopeParameter] = $scope;
        }
        try {
            $url = $this->urls->generate($page->route, $parameters, UrlGeneratorInterface::ABSOLUTE_PATH);
        } catch (Throwable) {
            return null;
        }
        $request = new SearchAccessRequest($userIdentifier, $url, $page->route, $parameters, $kind, $page->attribute, $page->subject, $scope);

        return SearchItem::isSafeUrl($url) && $this->access->isAllowed($request) ? $url : null;
    }

    /**
     * The texts of an entry, raw: label, breadcrumb, UI-locale keywords and the keywords (and labels) of the other
     * locales — capped per locale (MAX_KEYWORDS), identical ones kept once, at most MAX_OTHER_KEYWORDS of the other
     * locales (no further locale is translated once it is reached) — plus all of them folded in one string, so that an
     * entry no term can match is rejected without folding its texts one by one.
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<string>, 3: list<string>, 4: string}
     */
    private function texts(SearchPage $page, ?string $locale): array
    {
        $trail = $this->trail($page, $locale);
        $seen  = [];
        $own   = [];
        $other = [];
        // Plain-text entries (no domain) are the same in every locale: one pass.
        $locales = $page->translationDomain === null ? [$locale] : $this->keywordLocales($locale);
        foreach ($locales as $position => $keywordLocale) {
            if ($position > 0 && count($other) >= self::MAX_OTHER_KEYWORDS) {
                break;
            }
            $keywords = $page->keywords !== null ? $this->keywords($page->keywords, $page->translationDomain, $keywordLocale) : [];
            // In another locale the label is one more keyword (an English viewer typing "ajustes" finds "Settings").
            if ($position > 0 && $page->translationDomain !== null) {
                $label = $this->text($page->label, $page->translationDomain, $keywordLocale);
                if ($label !== $page->label) {
                    $keywords[] = $label;
                }
            }
            foreach ($keywords as $keyword) {
                if (isset($seen[$keyword]) || ($position > 0 && count($other) >= self::MAX_OTHER_KEYWORDS)) {
                    continue;
                }
                $seen[$keyword] = true;
                if ($position === 0) {
                    $own[] = $keyword;
                } else {
                    $other[] = $keyword;
                }
            }
        }
        $label = [$this->text($page->label, $page->translationDomain, $locale)];
        $trail = $trail !== '' ? [$trail] : [];

        return [$label, $trail, $own, $other, TextNormalizer::fold(implode("\x1F", [...$label, ...$trail, ...$own, ...$other]))];
    }

    /**
     * Best exact score of an entry (TIER_WEIGHTS: its label in full, the breadcrumb at 0.9, its UI-locale keywords at
     * 0.855 and the other locales at 0.765); a text that cannot beat the best score so far is not compared (nor folded).
     *
     * @param array{0: list<string>, 1: list<string>, 2: list<string>, 3: list<string>, 4: string} $texts
     */
    private function score(array $texts, SearchQuery $query, int &$evaluations): ?float
    {
        $best = null;
        foreach (self::TIER_WEIGHTS as $tier => $weight) {
            $haystacks = $texts[$tier];
            if ($haystacks === [] || ($best !== null && $best >= $weight)) {
                continue;
            }
            $score = $query->scoreFolded(array_map(FoldedText::haystack(...), $haystacks), $evaluations, SearchQuery::MATCH_EXACT);
            if ($score !== null && ($best === null || $score * $weight > $best)) {
                $best = $score * $weight;
            }
        }

        return $best;
    }

    private function item(SearchPage $page, string $url, ?string $locale, ?float $score): SearchItem
    {
        return new SearchItem($this->text($page->label, $page->translationDomain, $locale), $url, $this->trail($page, $locale), $page->icon ?? $this->defaultIcon, null, self::KEY, $score);
    }

    private function trail(SearchPage $page, ?string $locale): string
    {
        return implode(' › ', array_map(fn (string $crumb): string => $this->text($crumb, $page->translationDomain, $locale), $page->trail));
    }

    private function text(string $key, ?string $domain, ?string $locale): string
    {
        return $domain === null ? $key : $this->translator->trans($key, [], $domain, $locale);
    }

    /**
     * @return list<string|null> The UI locale (null: the translator's) first, then the other locales
     */
    private function keywordLocales(?string $locale): array
    {
        $locales = [$locale];
        foreach ($this->locales as $other) {
            if (!in_array($other, $locales, true)) {
                $locales[] = $other;
            }
        }

        return $locales;
    }

    /**
     * @return list<string> Keywords of one locale (none when the key has no message there)
     */
    private function keywords(string $key, ?string $domain, ?string $locale): array
    {
        $message = $this->text($key, $domain, $locale);
        if ($domain !== null && $message === $key) {
            return [];
        }

        $keywords = preg_split('/\s*,[\s,]*/u', trim($message, " \t\n\r\0\x0B,"), self::MAX_KEYWORDS + 1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($keywords) > self::MAX_KEYWORDS) {
            // Audit SPd-L2: tell the host its list is longer than what is matched (the key, never the query).
            $this->logger?->debug('Search page keywords {key} ({locale}) cut to {max}: the extra ones are ignored.', ['key' => $key, 'locale' => $locale ?? '-', 'max' => self::MAX_KEYWORDS]);
        }

        return array_slice($keywords, 0, self::MAX_KEYWORDS);
    }
}
