<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\Everywhere;

use Closure;
use Nowo\SearchKitBundle\DependencyInjection\Configuration;
use Nowo\SearchKitBundle\Model\SearchAccessRequest;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchTerm;
use Nowo\SearchKitBundle\Search\Access\SearchAccess;
use Nowo\SearchKitBundle\Search\Page\PageRegistryProvider;
use Nowo\SearchKitBundle\Search\Text\FoldedText;
use Nowo\SearchKitBundle\Search\Text\TextMatcher;
use PHPUnit\Framework\TestCase;

use function sprintf;

use const STDERR;

/**
 * Audit SP3-L1 (1.8.1): with `matching.fuzzy`, the typo budget lists every typo match of a Beacon-size registry, a
 * larger one degrades by relevance (label typos first) rather than by configuration order, and the ranking equals
 * comparing every tier wherever the budget suffices. Audit SP3-I1: an exact entry also gets its label typo score.
 */
final class TypoBudgetTest extends TestCase
{
    private const array WEIGHTS = [1.0, 0.9, 0.95 * 0.9, 0.85 * 0.9];

    public function testABeaconSizeRegistryListsEveryTypoMatch(): void
    {
        // The auditor's shape: 150 entries × 8 keywords × 7 locales, every keyword carrying "password".
        $logger   = Fixtures::logger();
        $registry = $this->translatedRegistry(150, 8, static fn (int $i, string $locale, int $k): string => 'password ' . $locale . 'word' . $k . ' entry' . $i, static fn (int $i, string $locale): string => 'Page ' . $locale . ' ' . $i, $logger);
        $synonyms = array_map(static fn (int $i): SearchTerm => new SearchTerm('sinonimo' . $i, $i % 2 === 0 ? 'en' : 'es', true), range(1, 24));
        $group    = $registry->search(new SearchQuery('paswword', null, 5, null, false, 'u', [], 'en', $synonyms, true));

        self::assertNotNull($group);
        self::assertSame(150, $group->total, 'every typo match is listed');
        self::assertSame(array_map(static fn (int $i): string => '/issues/' . $i, range(0, 149)), array_map(static fn (SearchItem $item): string => $item->url, $group->items));
        foreach ($group->items as $item) {
            // One edit on a UI-locale keyword: 0.45 × 0.855 (the other locales cannot beat it).
            self::assertEqualsWithDelta(0.45 * 0.95 * 0.9, $item->score, 1e-9);
        }
        self::assertSame([], $logger->lines, 'no ceiling');
    }

    public function testALargerRegistryDegradesByRelevanceNotByConfigurationOrder(): void
    {
        // 500 × 32 × 7: "password" only in another locale's keywords, except the last ten entries, whose label has a
        // typo match. The budget runs out in the keyword tiers; the label matches (the most relevant) are all listed.
        $logger   = Fixtures::logger();
        $registry = $this->translatedRegistry(500, 32, static fn (int $i, string $locale, int $k): string => ($locale === 'es' && $k === 1 ? 'password ' : '') . $locale . 'word' . $i . 'k' . $k . ' extra' . $k, static fn (int $i, string $locale): string => ($i >= 490 && $locale === 'en' ? 'Passwords ' : 'Eintrag ' . $locale . ' ') . $i, $logger);
        $group    = $registry->search(new SearchQuery('paswword', null, 5, null, false, 'u', [], 'en', [], true));

        self::assertNotNull($group);
        $urls = array_map(static fn (SearchItem $item): string => $item->url, $group->items);
        foreach (range(490, 499) as $i) {
            self::assertContains('/issues/' . $i, $urls, 'label typo match #' . $i);
        }
        self::assertLessThan(500, $group->total, 'the least relevant typo matches are cut');
        self::assertSame(['notice: Search page registry stopped at its evaluation ceiling (100000).'], $logger->lines);
        fwrite(STDERR, sprintf("\n[typo] 500x32x7 label matches at the end of the configuration: %d rows listed", $group->total));
    }

    public function testExactMatchesAreNeverLostAndRankingEqualsEveryTier(): void
    {
        $pages = [];
        foreach ([
            ['Password reset', ['Account'], 'credentials, login'],
            ['Passwort settings', [], 'superclave'],
            ['Profile', ['Settings', 'Account'], 'pasword, avatar picture'],
            ['Invoices', ['Billing'], 'receipts, pasword reset link'],
            ['Pasword policy', [], null],
            ['Reset tokens', ['Security'], 'passwrd, rotate keys'],
            ['Unrelated', [], 'nothing here'],
            ['Settings', ['Reset password'], 'preferences'],
        ] as $i => [$label, $trail, $keywords]) {
            $pages[] = ['id' => 'p' . $i, 'label' => $label, 'translation_domain' => null, 'route' => 'issue', 'route_parameters' => ['id' => $i], 'trail' => $trail, 'icon' => null, 'keywords' => $keywords, 'scope' => 'none', 'attribute' => null, 'subject' => null, 'scope_parameter' => null];
        }
        $registry = new PageRegistryProvider($pages, [], Fixtures::access(), Fixtures::router(), Fixtures::translator(), ['en']);
        foreach ([['password', [new SearchTerm('clave', 'es', true)]], ['paswword', []], ['pasword reset', []], ['passwrod', []], ['setings', []], ['reset', []]] as [$text, $terms]) {
            $query  = new SearchQuery($text, null, 5, null, false, 'u', [], 'en', $terms, true);
            $group  = $registry->search($query);
            $scores = [];
            foreach ($group->items ?? [] as $item) {
                $scores[$item->url] = $item->score;
            }
            foreach ($pages as $i => $page) {
                $expected = $this->everyTier($page, $text, $terms);
                if ($expected === null) {
                    self::assertArrayNotHasKey('/issues/' . $i, $scores, $text . ' #' . $i);

                    continue;
                }
                self::assertArrayHasKey('/issues/' . $i, $scores, $text . ' #' . $i);
                self::assertEqualsWithDelta($expected, $scores['/issues/' . $i], 1e-9, $text . ' #' . $i);
            }
        }
        // SP3-I1: the synonym hit in the keywords (0.6 × 0.75 × 0.855) no longer hides the label typo (0.45).
        $group = $registry->search(new SearchQuery('password', null, 5, null, false, 'u', [], 'en', [new SearchTerm('clave', 'es', true)], true));
        self::assertNotNull($group);
        $label = array_values(array_filter($group->items, static fn (SearchItem $item): bool => $item->url === '/issues/1'));
        self::assertCount(1, $label);
        self::assertEqualsWithDelta(0.45, $label[0]->score, 1e-9);
    }

    public function testTheTypoPassOnlyScoresVisibleEntries(): void
    {
        $denied = static fn (SearchAccessRequest $request): bool => (int) $request->routeParameters['id'] % 2 === 0;
        $build  = fn (int $n, ?Closure $allow): PageRegistryProvider => $this->translatedRegistry($n, 8, static fn (int $i, string $locale, int $k): string => 'password ' . $locale . 'word' . $k . ' entry' . $i, static fn (int $i, string $locale): string => 'Page ' . $locale . ' ' . $i, Fixtures::logger(), $allow);
        $query  = new SearchQuery('paswword', null, 5, null, false, 'u', [], 'en', [], true);
        $hidden = $build(300, $denied)->search($query);
        self::assertNotNull($hidden);
        self::assertSame(150, $hidden->total);
        foreach ($hidden->items as $item) {
            self::assertSame(0, (int) substr($item->url, 8) % 2, 'a denied entry is never listed');
        }
    }

    public function testScoreTyposEqualsTheTypoTierOfScoreFolded(): void
    {
        $texts = [['Password reset'], ['pasword', 'reset link'], ['reset', 'pasword'], ['xx'], ['a b', 'paswords'], [str_repeat('word ', 40) . 'pasword'], [str_repeat('w ', 70) . 'pasword', 'reset']];
        foreach (['paswword', 'pasword reset', 'passwrd', 'reset', 'ab', 'pasword res'] as $text) {
            $query = new SearchQuery($text, null, 5, null, false, 'u', [], 'en', [new SearchTerm($text . 's'), new SearchTerm('clave', 'es', true)], true);
            foreach ($texts as $haystacks) {
                $evaluations = 0;
                self::assertSame(
                    $query->scoreFolded(array_map(FoldedText::haystack(...), $haystacks), $evaluations, SearchQuery::MATCH_FUZZY),
                    $query->scoreTypos($haystacks, $evaluations),
                    $text . ' / ' . implode(' | ', $haystacks),
                );
            }
        }
        $plain = new SearchQuery('paswword', null, 5, null, false, 'u');
        self::assertNull($plain->scoreTypos(['password']), 'no fuzzy term');
    }

    public function testWordsOfEqualsTheWordsOfEachHaystack(): void
    {
        foreach ([['Café Olé', 'Straße ÆON', 'x-y'], ['a b'], [str_repeat('é ', 70)], [str_repeat('ab ', 30), 'c'], ['1', '0', 'a1']] as $texts) {
            $merged = array_values(array_unique(array_merge(...array_map(static fn (string $text): array => FoldedText::haystack($text)->words(), $texts))));
            self::assertSame($merged, FoldedText::wordsOf($texts));
        }
        self::assertCount(64, FoldedText::wordsOf([implode(' ', array_map(static fn (int $i): string => 'w' . $i, range(1, 80)))]), 'a long text keeps the haystack bound');
        self::assertSame(0.45, TextMatcher::fuzzyWords(FoldedText::needle('paswword'), ['password']));
        self::assertNull(TextMatcher::fuzzyWords(FoldedText::needle('paswword'), []));
        // The entry-level pre-check of 1.8.0 is still public (no longer used by the registry).
        self::assertTrue(TextMatcher::mayMatchFuzzily(FoldedText::needle('paswword'), ['password']));
        $evaluations = 0;
        $query       = new SearchQuery('paswword', null, 5, null, false, 'u', [], 'en', [new SearchTerm('clave', 'es', true)], true);
        self::assertTrue($query->mayMatchFuzzily('page ' . "\x1F" . 'password reset', $evaluations));
        self::assertSame(3, $evaluations, 'charged per candidate word');
        self::assertFalse($query->mayMatchFuzzily('nothing here', $evaluations));
        self::assertFalse((new SearchQuery('paswword', null, 5, null, false, 'u'))->mayMatchFuzzily('password'), 'no fuzzy term');
    }

    /**
     * Reference: every tier compared — the best exact score over the tiers, and for an entry without an exact match the
     * best typo score over the tiers; an exact entry also gets its label's typo score (SP3-I1).
     *
     * @param array{label: string, trail: list<string>, keywords: string|null} $page
     * @param list<SearchTerm> $terms
     */
    private function everyTier(array $page, string $text, array $terms): ?float
    {
        $tiers = [[$page['label']], $page['trail'] !== [] ? [implode(' › ', $page['trail'])] : [], $page['keywords'] !== null ? explode(', ', $page['keywords']) : [], []];
        $exact = new SearchQuery($text, null, 5, null, false, 'u', [], 'en', $terms);
        $fuzzy = new SearchQuery($text, null, 5, null, false, 'u', [], 'en', $terms, true);
        $best  = null;
        foreach ($tiers as $tier => $texts) {
            $score = $texts !== [] ? $exact->score(...$texts) : null;
            $best  = $score !== null ? max($best ?? 0.0, $score * self::WEIGHTS[$tier]) : $best;
        }
        if ($best !== null) {
            $label = $fuzzy->score($page['label']);

            return $label !== null ? max($best, $label) : $best;
        }
        foreach ($tiers as $tier => $texts) {
            $score = $texts !== [] ? $fuzzy->score(...$texts) : null;
            $best  = $score !== null ? max($best ?? 0.0, $score * self::WEIGHTS[$tier]) : $best;
        }

        return $best;
    }

    /**
     * @param callable(int, string, int): string $keyword
     * @param callable(int, string): string $label
     */
    private function translatedRegistry(int $n, int $keywords, callable $keyword, callable $label, RecordingLogger $logger, ?Closure $allow = null): PageRegistryProvider
    {
        $messages = [];
        foreach (Configuration::LOCALES as $locale) {
            $messages[$locale]['search.group.pages'] = 'Pages';
            for ($i = 0; $i < $n; ++$i) {
                $messages[$locale]['p' . $i . '.label'] = $label($i, $locale);
                $messages[$locale]['p' . $i . '.kw']    = implode(', ', array_map(static fn (int $k): string => $keyword($i, $locale, $k), range(1, $keywords)));
            }
        }
        $pages = [];
        for ($i = 0; $i < $n; ++$i) {
            $pages[] = ['id' => 'p' . $i, 'label' => 'p' . $i . '.label', 'translation_domain' => 'messages', 'route' => 'issue', 'route_parameters' => ['id' => $i], 'trail' => [], 'icon' => null, 'keywords' => 'p' . $i . '.kw', 'scope' => 'none', 'attribute' => null, 'subject' => null, 'scope_parameter' => null];
        }

        return new PageRegistryProvider($pages, [], new SearchAccess([Fixtures::checker($allow ?? static fn (): bool => true)], Fixtures::router()), Fixtures::router(), Fixtures::translator($messages), Configuration::LOCALES, 500, 'tabler:file', $logger);
    }
}
