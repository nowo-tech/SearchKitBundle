<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\Everywhere;

use InvalidArgumentException;
use Nowo\SearchKitBundle\Model\SearchFilter;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchTerm;
use Nowo\SearchKitBundle\Search\Filter\SearchFilterParser;
use Nowo\SearchKitBundle\Search\Text\TextMatcher;
use Nowo\SearchKitBundle\Search\Text\TextNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Accent / case folding, the match tiers, fuzzy matching with bounded cost, and the typed filter parser.
 */
final class TextAndFiltersTest extends TestCase
{
    public function testFoldingCoversTheSevenLocales(): void
    {
        self::assertSame('contrasena', TextNormalizer::fold('Contraseña'));
        self::assertSame('ubersicht strasse', TextNormalizer::fold("  Übersicht \t STRASSE "));
        self::assertSame('strasse', TextNormalizer::fold('Straße'));
        self::assertSame('ca va a l ecole, coeur', TextNormalizer::fold('Ça va à l école, Cœur'));
        self::assertSame('cafe', TextNormalizer::fold("cafe\u{0301}"), 'combining marks are dropped');
        self::assertSame(['a', 'b', 'c'], TextNormalizer::words('a-b c', 3));
        self::assertSame(['a', 'b'], TextNormalizer::words('a b c', 2));
        self::assertSame([], TextNormalizer::words(''));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool, 3: float|null}>
     */
    public static function tiers(): iterable
    {
        yield 'equal' => ['Perfil', 'perfil', false, 1.0];
        yield 'prefix' => ['perf', 'Perfil de usuario', false, 0.9];
        yield 'word start' => ['usu', 'Perfil de usuario', false, 0.8];
        yield 'contains' => ['fil', 'Perfil', false, 0.7];
        yield 'every word prefixes' => ['usu perf', 'Perfil de usuario', false, 0.6];
        yield 'one word only' => ['zzz', 'Perfil', false, null];
        yield 'missing word' => ['usu zzz', 'Perfil de usuario', false, null];
        yield 'empty needle' => ['  ', 'Perfil', false, null];
        yield 'empty haystack' => ['x', '', false, null];
        yield 'fuzzy off' => ['pasword', 'Password', false, null];
        yield 'fuzzy one edit' => ['pasword', 'Password reset', true, 0.45];
        yield 'fuzzy while typing' => ['contrs', 'Contraseña', true, 0.45];
        yield 'fuzzy two edits' => ['pasworrd', 'Password', true, 0.4];
        yield 'fuzzy too far' => ['pzzword', 'Password', true, null];
        yield 'fuzzy short word' => ['pas', 'xyz', true, null];
        yield 'fuzzy no words' => ['--', 'Password', true, null];
        yield 'fuzzy long word' => [str_repeat('a', 40), str_repeat('a', 39) . 'b', true, null];
        yield 'fuzzy both words' => ['pasword resett', 'Password reset', true, 0.4];
        yield 'fuzzy one misses' => ['pasword zzzzzz', 'Password reset', true, null];
    }

    #[DataProvider('tiers')]
    public function testMatchTiers(string $needle, string $haystack, bool $fuzzy, ?float $expected): void
    {
        $score = TextMatcher::score($needle, $haystack, $fuzzy);
        if ($expected === null) {
            self::assertNull($score);

            return;
        }
        self::assertNotNull($score);
        self::assertEqualsWithDelta($expected, $score, 0.0001);
    }

    public function testCostIsBounded(): void
    {
        self::assertSame(0, TextMatcher::allowedEdits('abc'));
        self::assertSame(1, TextMatcher::allowedEdits('abcdef'));
        self::assertSame(2, TextMatcher::allowedEdits('abcdefg'));
        // Only the first MAX_NEEDLE characters and MAX_HAYSTACK characters are compared.
        self::assertNull(TextMatcher::score(str_repeat('x', 64) . 'needle', 'needle'));
        self::assertNull(TextMatcher::score('needle', str_repeat('x', TextMatcher::MAX_HAYSTACK) . ' needle'));
        $start = hrtime(true);
        TextMatcher::score(str_repeat('abcdefgh ', 200), str_repeat('hgfedcba ', 2000), true);
        self::assertLessThan(500_000_000, hrtime(true) - $start, 'a huge input stays cheap');
    }

    public function testTheQueryScoresItsSynonymsBelowTheTypedText(): void
    {
        $query = new SearchQuery('clave', null, 5, null, false, 'u', [], 'es', [new SearchTerm('password', 'en', true), new SearchTerm('contraseña', 'es', true)]);
        self::assertCount(3, $query->terms());
        self::assertEqualsWithDelta(1.0, $query->score('Clave'), 0.0001);
        self::assertEqualsWithDelta(0.85, $query->score('Contrasena'), 0.0001, 'synonym in the UI locale');
        self::assertEqualsWithDelta(0.75, $query->score('Password'), 0.0001, 'synonym in another locale');
        self::assertTrue($query->matches('nothing', 'PASSWORD reset'));
        self::assertFalse($query->matches('nothing'));

        $empty = new SearchQuery('', null, 5, null, false, 'u', [], null, [new SearchTerm('x', null, true)]);
        self::assertSame(['x'], array_map(static fn (SearchTerm $term): string => $term->text, $empty->terms()));
        self::assertEqualsWithDelta(0.75, (float) $empty->score('x'), 0.0001, 'no UI locale: every synonym counts as another locale');

        $fuzzy = new SearchQuery('pasword', null, 5, null, false, 'u', [], null, [new SearchTerm('factura', 'es', true)], true);
        self::assertTrue($fuzzy->matches('Password'));
        self::assertFalse($fuzzy->matches('doble factor'), 'never fuzzy on a synonym');
        self::assertTrue((new SearchQuery('Ñandú', null, 5, null, false, 'u'))->matches('nandu salvaje'), 'matches() folds accents (1.8.0)');
    }

    public function testFiltersOnTheQuery(): void
    {
        $query = new SearchQuery('bug', null, 5, null, false, 'u', [new SearchFilter('is', 'open'), new SearchFilter('assignee', 'me', true), new SearchFilter('is', 'urgent')]);
        self::assertTrue($query->hasFilters());
        self::assertSame(['open', 'urgent'], array_map(static fn (SearchFilter $f): string => $f->value, $query->filtersOf('is')));
        self::assertSame([], $query->filtersOf('project'));
        self::assertFalse((new SearchQuery('bug', null, 5, null, false, 'u'))->hasFilters());
    }

    public function testFilterValueObject(): void
    {
        $filter = new SearchFilter('assignee', 'Ana "the" Gómez', true);
        self::assertSame('-assignee:"Ana the Gómez"', $filter->toToken());
        self::assertSame(['key' => 'is', 'value' => 'open', 'negated' => false, 'token' => 'is:open'], (new SearchFilter('is', 'open'))->toArray());

        foreach ([['Is', 'x'], ['is', ''], ['is', str_repeat('x', 101)], ['1s', 'x']] as [$key, $value]) {
            try {
                new SearchFilter($key, $value);
                self::fail('Expected an exception for ' . $key);
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testTheParserKeepsOnlyConfiguredKeys(): void
    {
        $parser = new SearchFilterParser(['is', 'assignee', 'project'], 3);
        self::assertTrue($parser->isEnabled());
        self::assertSame(['is', 'assignee', 'project'], $parser->keys());

        [$text, $filters] = $parser->parse('checkout IS:open -assignee:"Ana Gómez" at 10:30 http://x.test project:');
        self::assertSame('checkout at 10:30 http://x.test', $text, 'other key:value pairs stay text; an empty value is dropped');
        self::assertSame(['is:open', '-assignee:"Ana Gómez"'], array_map(static fn (SearchFilter $f): string => $f->toToken(), $filters));

        [$text, $filters] = $parser->parse('is:a is:b is:c is:d rest');
        self::assertSame('rest', $text);
        self::assertCount(3, $filters, 'at most `max` filters; extra ones are dropped');

        [$text, $filters] = $parser->parse('project:"unclosed value');
        self::assertSame('', $text);
        self::assertSame('unclosed value', $filters[0]->value);

        [, $filters] = $parser->parse('is:' . str_repeat('v', 150));
        self::assertSame(SearchFilter::MAX_VALUE_LENGTH, mb_strlen($filters[0]->value));

        self::assertSame(['no colon here', []], $parser->parse('no colon here'));
        self::assertSame(['is:open', []], (new SearchFilterParser())->parse('is:open'), 'no keys: nothing parsed');
        self::assertFalse((new SearchFilterParser())->isEnabled());
    }
}
