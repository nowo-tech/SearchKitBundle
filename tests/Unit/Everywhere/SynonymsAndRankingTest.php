<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\Everywhere;

use DateTimeImmutable;
use Nowo\SearchKitBundle\Contract\SearchSynonymProviderInterface;
use Nowo\SearchKitBundle\DependencyInjection\Configuration;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchTerm;
use Nowo\SearchKitBundle\Search\Ranking\SearchRanker;
use Nowo\SearchKitBundle\Search\Synonym\ConceptGroupSynonymProvider;
use Nowo\SearchKitBundle\Search\Synonym\SynonymExpander;
use Nowo\SearchKitBundle\Search\Text\TextNormalizer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function count;

/**
 * The built-in concept groups, the synonym expansion (UI locale first, cross-locale, bounded) and the ranking.
 */
final class SynonymsAndRankingTest extends TestCase
{
    public function testEveryBundledConceptHasTheSevenLocalesAndNoWordTwice(): void
    {
        $groups = ConceptGroupSynonymProvider::bundled();
        self::assertGreaterThanOrEqual(40, count($groups));
        foreach ($groups as $index => $group) {
            self::assertSame(Configuration::LOCALES, array_keys($group), 'group ' . $index);
            foreach ($group as $locale => $words) {
                self::assertNotEmpty($words, $index . '/' . $locale);
                $folded = array_map(TextNormalizer::fold(...), $words);
                self::assertSame($folded, array_values(array_unique($folded)), $index . '/' . $locale);
            }
        }
    }

    public function testAWordOfAnyLocaleExpandsToItsConceptInTheAskedLocale(): void
    {
        $dictionary = new ConceptGroupSynonymProvider();
        self::assertSame(['password', 'passphrase'], $dictionary->synonymsOf('Contraseña', 'en'));
        self::assertSame(['clave'], $dictionary->synonymsOf('contrasena', 'es'), 'without the word itself');
        self::assertSame(['copia de seguridad', 'copia'], $dictionary->synonymsOf('respaldo', 'es'));
        self::assertSame(['Sicherung', 'Backup'], $dictionary->synonymsOf('respaldo', 'de'));
        self::assertSame([], $dictionary->synonymsOf('zzz', 'en'));
        self::assertSame([], $dictionary->synonymsOf('password', 'xx'));

        $custom = new ConceptGroupSynonymProvider([
            ['en' => ['car', 'auto'], 'es' => ['coche']],
            ['en' => ['auto', 'automatic'], 'es' => ['automático']],
            ['en' => ['car', ''], 'es' => []],
        ]);
        self::assertSame(['car', 'automatic'], $custom->synonymsOf('auto', 'en'), 'every concept of the word, deduplicated');
    }

    public function testTheExpanderAsksTheUiLocaleFirstAndReplacesWordsInPlace(): void
    {
        $expander = new SynonymExpander([new ConceptGroupSynonymProvider()], ['en', 'es', 'de'], 24);
        $terms    = $expander->expand('cambiar contraseña', 'es');
        $texts    = array_map(static fn (SearchTerm $term): string => $term->locale . ':' . $term->text, $terms);
        self::assertSame('es:editar contraseña', $texts[0], 'the UI locale first');
        self::assertContains('es:cambiar clave', $texts);
        self::assertContains('en:cambiar password', $texts);
        self::assertContains('de:cambiar Passwort', $texts);
        self::assertNotContains('es:cambiar contraseña', $texts, 'never the text itself');
        foreach ($terms as $term) {
            self::assertTrue($term->synonym);
        }

        self::assertSame(['en:password', 'en:passphrase', 'es:clave'], array_map(static fn (SearchTerm $t): string => $t->locale . ':' . $t->text, (new SynonymExpander([new ConceptGroupSynonymProvider()], ['en', 'es'], 3))->expand('contraseña', null)));
        self::assertSame([], $expander->expand('   ', 'en'));
        self::assertSame([], $expander->expand(str_repeat('password ', 10), 'en'), 'a long text is not expanded');
        self::assertSame('es', (new SynonymExpander([new ConceptGroupSynonymProvider()], ['en', 'es']))->expand('contraseña', '')[2]->locale ?? null);
    }

    public function testAFailingSynonymProviderIsLoggedAndSkipped(): void
    {
        $logger = Fixtures::logger();
        $broken = new class implements SearchSynonymProviderInterface {
            public function synonymsOf(string $word, string $locale): array
            {
                if ($locale === 'en') {
                    throw new RuntimeException('down');
                }

                return [' ', 'Same', 'same', 'valid'];
            }
        };
        $terms = (new SynonymExpander([$broken], ['en', 'es'], 24, $logger))->expand('same', 'es');
        self::assertSame(['valid'], array_map(static fn (SearchTerm $t): string => $t->text, $terms));
        self::assertContains('error: Search synonym provider ' . $broken::class . ' failed: RuntimeException.', $logger->lines);
    }

    public function testRowsAreSortedByScoreAndRecencyOnlyWhenRanked(): void
    {
        $now    = new DateTimeImmutable('2026-11-17');
        $ranker = new SearchRanker(0.25, 30.0);
        $plain  = new SearchGroup('g', 'G', [new SearchItem('b', '/b'), new SearchItem('a', '/a')]);
        self::assertSame($plain, $ranker->sort($plain, $now), 'unranked rows keep the provider order');

        $group = new SearchGroup('g', 'G', [
            new SearchItem('old', '/1', '', null, null, null, 0.8, new DateTimeImmutable('2025-01-01')),
            new SearchItem('new', '/2', '', null, null, null, 0.8, new DateTimeImmutable('2026-11-16')),
            new SearchItem('best', '/3', '', null, null, null, 1.0),
            new SearchItem('none', '/4'),
            new SearchItem('tie', '/5', '', null, null, null, 0.8, new DateTimeImmutable('2025-01-01')),
            new SearchItem('future', '/6', '', null, null, null, 0.0, new DateTimeImmutable('2027-01-01')),
        ], 9, null, null, true);
        $sorted = $ranker->sort($group, $now);
        self::assertSame(['new', 'best', 'old', 'tie', 'future', 'none'], array_map(static fn (SearchItem $i): string => $i->title, $sorted->items));
        self::assertSame(9, $sorted->total);
        self::assertTrue($sorted->exactTotal);
        self::assertEqualsWithDelta(0.25, $ranker->rank(new SearchItem('f', '/f', '', null, null, null, null, $now), $now), 0.0001);
        self::assertEqualsWithDelta(0.125, $ranker->rank(new SearchItem('f', '/f', '', null, null, null, null, $now->modify('-30 days')), $now), 0.0001);
        self::assertEqualsWithDelta(0.0, (new SearchRanker(0.0))->rank(new SearchItem('f', '/f', '', null, null, null, null, $now), $now), 0.0001);

        $top = $ranker->top([$group, new SearchGroup('h', 'H', [new SearchItem('other', '/o', '', null, null, null, 0.95)])], 3, $now);
        self::assertSame(['g:new', 'g:best', 'h:other'], array_map(static fn (array $row): string => $row['group'] . ':' . $row['item']->title, $top));
        self::assertSame([], $ranker->top([$group], 0, $now));
    }
}
