<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\Everywhere;

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
}
