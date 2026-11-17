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
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

use function array_key_exists;
use function count;
use function in_array;
use function is_string;

/**
 * The page registry (1.8.0): `nowo_search_kit.pages` plus the SearchPageSourceInterface entries, in the Pages group.
 *
 * For every candidate, in this order: scope rule (`none` entries only while unscoped, `required` entries only while
 * scoped), URL generation, **access check (deny by default)**, and only then matching — on the label, the breadcrumb
 * and the keywords (UI locale first, then the labels and keywords of the other `page_registry.locales`; keywords are
 * never shown). A denied entry is never
 * matched, counted, ranked or rendered, and the group total is the number of visible matches (exact).
 *
 * Also resolves recent references to registry pages (SearchReferenceResolverInterface). Holds no state.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class PageRegistryProvider implements IdentifierAwareSearchProviderInterface, SearchReferenceResolverInterface
{
    public const string KEY = 'pages';

    /** @var list<SearchPage> */
    private array $pages;

    /**
     * @param list<array<string, mixed>> $pages Normalised `nowo_search_kit.pages` rows
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
        /** @var list<array{id: string, label: string, translation_domain: string|null, route: string, route_parameters: array<string, scalar|null>, trail: list<string>, icon: string|null, keywords: string|null, scope: string, attribute: string|null, subject: string|null, scope_parameter: string|null}> $pages */
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
        $found = [];
        foreach ($this->candidates($query) as $page) {
            $url = $this->visibleUrl($page, $query->userIdentifier, $query->scope, SearchAccessRequest::KIND_PAGE);
            if ($url === null || array_key_exists($url, $found)) {
                continue;
            }
            $score = $this->score($page, $query);
            if ($score !== null) {
                $found[$url] = $this->item($page, $url, $query->locale, $score);
            }
        }
        if ($found === []) {
            return null;
        }

        return new SearchGroup(self::KEY, $this->translator->trans('search.group.pages', [], 'NowoSearchKitBundle'), array_values($found), count($found), null, null, true);
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
        if (!SearchItem::isSafeUrl($url)) {
            return null;
        }
        $request = new SearchAccessRequest($userIdentifier, $url, $page->route, $parameters, $kind, $page->attribute, $page->subject, $scope);

        return $this->access->isAllowed($request) ? $url : null;
    }

    private function score(SearchPage $page, SearchQuery $query): ?float
    {
        $best   = $query->score($this->text($page->label, $page->translationDomain, $query->locale));
        $trail  = $this->trail($page, $query->locale);
        $scores = [$trail !== '' ? $query->score($trail) : null];
        foreach ($this->keywordLocales($query->locale) as $position => $locale) {
            $keywords = $page->keywords !== null ? $this->keywords($page->keywords, $page->translationDomain, $locale) : [];
            // In another locale the label is one more keyword (an English viewer typing "ajustes" finds "Settings").
            if ($position > 0 && $page->translationDomain !== null) {
                $label = $this->text($page->label, $page->translationDomain, $locale);
                if ($label !== $page->label) {
                    $keywords[] = $label;
                }
            }
            if ($keywords !== []) {
                $score    = $query->score(...$keywords);
                $scores[] = $score !== null ? $score * ($position === 0 ? 0.95 : 0.85) : null;
            }
        }
        foreach ($scores as $score) {
            if ($score !== null && ($best === null || $score * 0.9 > $best)) {
                $best = $score * 0.9;
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

        return array_values(array_filter(array_map(trim(...), explode(',', $message)), static fn (string $word): bool => $word !== ''));
    }
}
