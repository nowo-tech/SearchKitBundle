<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\Everywhere;

use Nowo\SearchKitBundle\DependencyInjection\Configuration;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchTerm;
use Nowo\SearchKitBundle\Search\Access\SearchAccess;
use Nowo\SearchKitBundle\Search\Page\PageRegistryProvider;
use PHPUnit\Framework\TestCase;

use function sprintf;

use const STDERR;

/**
 * Audit SP-M1: the registry matching cost has a ceiling. 500 entries × 30 keywords × 25 terms (the largest allowed
 * candidate list and the default synonym cap) must stay well under a keystroke budget, fuzzy included.
 */
final class CostTest extends TestCase
{
    /** Budget of one registry search on a CI runner (milliseconds). */
    private const float BUDGET_MS = 200.0;

    public function testTheLargestRegistryStaysUnderTheBudget(): void
    {
        $keywords = implode(', ', array_map(static fn (int $i): string => 'keyword' . $i . ' configuración avanzada', range(1, 30)));
        $pages    = [];
        for ($i = 0; $i < 500; ++$i) {
            $pages[] = ['id' => 'p' . $i, 'label' => 'Página número ' . $i, 'translation_domain' => null, 'route' => 'issue', 'route_parameters' => ['id' => $i], 'trail' => ['Ajustes', 'Sección ' . $i], 'icon' => null, 'keywords' => $keywords, 'scope' => 'none', 'attribute' => null, 'subject' => null, 'scope_parameter' => null];
        }
        $registry = new PageRegistryProvider($pages, [], new SearchAccess([Fixtures::checker(static fn (): bool => true)], Fixtures::router()), Fixtures::router(), Fixtures::translator());
        $terms    = array_map(static fn (int $i): SearchTerm => new SearchTerm('sinonimo' . $i . ' extra', $i % 2 === 0 ? 'en' : 'es', true), range(1, 24));

        foreach (['zqxwzqxw nothing', 'keyword29 avanzada', 'página'] as $text) {
            foreach ([false, true] as $fuzzy) {
                $query = new SearchQuery($text, null, 5, null, false, 'u', [], 'en', $terms, $fuzzy);
                self::assertCount(25, $query->terms());
                $start = hrtime(true);
                $registry->search($query);
                $ms = (hrtime(true) - $start) / 1e6;
                fwrite(STDERR, sprintf("\n[cost] registry 500x30x25 fuzzy=%s text=%s: %.1f ms", $fuzzy ? 'y' : 'n', $text, $ms));
                self::assertLessThan(self::BUDGET_MS, $ms, $text . ($fuzzy ? ' (fuzzy)' : ''));
            }
        }
    }

    /**
     * Audit SPd-I1: the heaviest realistic path — entries translated in the 7 locales, distinct keywords per locale.
     */
    public function testATranslatedRegistryInSevenLocalesStaysUnderTheBudget(): void
    {
        $registry = $this->translatedRegistry();
        $terms    = array_map(static fn (int $i): SearchTerm => new SearchTerm('sinonimo' . $i . ' extra', $i % 2 === 0 ? 'en' : 'es', true), range(1, 24));
        foreach (['zqxwzqxw nothing', 'word1 entry', 'pass', 'enword4k1', 'entry4'] as $text) {
            foreach ([false, true] as $fuzzy) {
                $query = new SearchQuery($text, null, 5, null, false, 'u', [], 'en', $terms, $fuzzy);
                $start = hrtime(true);
                $registry->search($query);
                $ms = (hrtime(true) - $start) / 1e6;
                fwrite(STDERR, sprintf("\n[cost] translated 500x32x7x25 fuzzy=%s text=%s: %.1f ms", $fuzzy ? 'y' : 'n', $text, $ms));
                self::assertLessThan(self::BUDGET_MS, $ms, $text . ($fuzzy ? ' (fuzzy)' : ''));
            }
        }
    }

    /**
     * Audit SPd-L1: with fuzzy on, the evaluation ceiling must never drop an exact match (exact pass first, fuzzy on
     * the remaining budget only for the entries that did not match exactly).
     */
    public function testFuzzyCostNeverDropsAnExactMatch(): void
    {
        $registry = $this->translatedRegistry();
        $expected = array_merge([4], range(40, 49), range(400, 499));
        foreach ([false, true] as $fuzzy) {
            $group = $registry->search(new SearchQuery('entry4', null, 5, null, false, 'u', [], 'en', [], $fuzzy));
            self::assertNotNull($group);
            $urls = array_map(static fn ($item): string => $item->url, $group->items);
            foreach ($expected as $id) {
                self::assertContains('/issues/' . $id, $urls, 'exact match #' . $id . ($fuzzy ? ' (fuzzy on)' : ''));
            }
        }
    }

    private function translatedRegistry(): PageRegistryProvider
    {
        $messages = [];
        foreach (Configuration::LOCALES as $locale) {
            for ($i = 0; $i < 500; ++$i) {
                $messages[$locale]['p' . $i . '.label'] = ($locale === 'en' ? 'Entry' : 'Eintrag-' . $locale . '-') . $i;
                $messages[$locale]['p' . $i . '.kw']    = implode(', ', array_map(static fn (int $k): string => $locale . 'word' . $i . 'k' . $k . ' configuración', range(1, 32)));
            }
        }
        $pages = [];
        for ($i = 0; $i < 500; ++$i) {
            $pages[] = ['id' => 'p' . $i, 'label' => 'p' . $i . '.label', 'translation_domain' => 'messages', 'route' => 'issue', 'route_parameters' => ['id' => $i], 'trail' => [], 'icon' => null, 'keywords' => 'p' . $i . '.kw', 'scope' => 'none', 'attribute' => null, 'subject' => null, 'scope_parameter' => null];
        }

        return new PageRegistryProvider($pages, [], new SearchAccess([Fixtures::checker(static fn (): bool => true)], Fixtures::router()), Fixtures::router(), Fixtures::translator($messages), Configuration::LOCALES, 500);
    }
}
