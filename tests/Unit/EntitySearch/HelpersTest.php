<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\EntitySearch;

use InvalidArgumentException;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Search\Query\LikePattern;
use Nowo\SearchKitBundle\Search\Query\ScopeParser;
use Nowo\SearchKitBundle\Search\Query\SearchWindow;
use Nowo\SearchKitBundle\Search\Query\SearchWords;
use Nowo\SearchKitBundle\Search\Query\SeeAllLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function str_repeat;

final class HelpersTest extends TestCase
{
    public function testLikeEscapesWildcardsAndTheEscapeCharacter(): void
    {
        self::assertSame('100!% a!_b !!x \\y', LikePattern::escape('100% a_b !x \\y'));
        self::assertSame('%!%%', LikePattern::contains('%'));
        self::assertSame("LOWER(t.title) LIKE :p ESCAPE '!'", LikePattern::condition('t.title', 'p'));
        self::assertSame("(LOWER(a) LIKE :p ESCAPE '!' OR LOWER(t.b) LIKE :p ESCAPE '!')", LikePattern::anyField(['a', 't.b'], 'p'));
        self::assertTrue(LikePattern::isField('t.title_2'));
        self::assertFalse(LikePattern::isField('t.title)'));
        self::assertFalse(LikePattern::isField("t.title\n"));
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: string}>
     */
    public static function unsafeLike(): iterable
    {
        yield 'field' => [['title; --'], 'p'];
        yield 'nested field' => [['a.b.c'], 'p'];
        yield 'parameter' => [['title'], 'p-1'];
        yield 'parameter with newline' => [['title'], "p\n"];
        yield 'no field' => [[], 'p'];
    }

    /**
     * @param list<string> $fields
     */
    #[DataProvider('unsafeLike')]
    public function testUnsafeLikeInputThrows(array $fields, string $parameter): void
    {
        $this->expectException(InvalidArgumentException::class);
        LikePattern::anyField($fields, $parameter);
    }

    public function testWordsAreBoundedLowerCaseAndDistinct(): void
    {
        self::assertSame(['abc', '50%', 'x_y'], SearchWords::words("  ABC\t50%  abc x_y "));
        self::assertSame([], SearchWords::words('   '));
        self::assertCount(SearchWords::MAX_WORDS, SearchWords::words('a b c d e f g h i j'));
        self::assertSame([str_repeat('a', SearchWords::MAX_WORD_LENGTH)], SearchWords::words(str_repeat('A', 100)));
    }

    public function testFullTextTermsKeepOnlyLettersAndDigits(): void
    {
        self::assertSame(['niño', 'x', 'y2', 'or'], SearchWords::fullTextTerms('Niño* +x -"y2" OR'));
        self::assertSame([], SearchWords::fullTextTerms('%%_ "*" @'));
    }

    public function testScopeParserTreatsTheScopeAsClientInput(): void
    {
        self::assertTrue(ScopeParser::isValid('project:42'));
        self::assertFalse(ScopeParser::isValid(null));
        self::assertFalse(ScopeParser::isValid('a b'));
        self::assertFalse(ScopeParser::isValid("project:1\n"));
        self::assertNull(ScopeParser::value("project:1\n", 'project'));
        self::assertSame('ab-1', ScopeParser::value('project:ab-1', 'project'));
        self::assertNull(ScopeParser::value('board:1', 'project'));
        self::assertNull(ScopeParser::value('project:', 'project'));
        self::assertNull(ScopeParser::value('project:a:b', 'project'));
        self::assertNull(ScopeParser::value('project:1', ''));
        self::assertNull(ScopeParser::value(null, 'project'));
        self::assertSame(42, ScopeParser::positiveInt('project:42', 'project'));
        self::assertNull(ScopeParser::positiveInt('project:042', 'project'));
        self::assertNull(ScopeParser::positiveInt('project:0', 'project'));
        self::assertNull(ScopeParser::positiveInt('project:4x', 'project'));
        self::assertNull(ScopeParser::positiveInt('project:9999999999999999999', 'project'));
        self::assertNull(ScopeParser::positiveInt('board:4', 'project'));
    }

    /**
     * 1.10.1: isExact() without the fetched count keeps the 1.9 rule and is deprecated (required in 2.0).
     */
    #[IgnoreDeprecations]
    public function testIsExactWithoutTheFetchedCountIsDeprecated(): void
    {
        $window = SearchWindow::of(1, 3, 10);
        self::assertInstanceOf(SearchWindow::class, $window);

        $this->expectUserDeprecationMessage('Since nowo-tech/search-kit-bundle 1.10.1: Calling "' . SearchWindow::class . '::isExact()" without the number of fetched rows is deprecated; it is required in 2.0.');
        self::assertTrue($window->isExact([1, 2, 3]));
        self::assertFalse($window->isExact([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]));
    }

    public function testWindowIsBounded(): void
    {
        $window = SearchWindow::of(2, 3, 10);
        self::assertInstanceOf(SearchWindow::class, $window);
        self::assertSame(10, $window->fetchLimit());
        self::assertTrue($window->isExact([1, 2, 3, 4, 5, 6, 7, 8, 9], 9));
        self::assertFalse($window->isExact([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], 10));
        self::assertTrue($window->isExact([1, 2, 3], 9), '1.10.1: the fetch did not fill the window.');
        self::assertFalse($window->isExact([1, 2, 3], 10), '1.10.1: hidden rows filled the window.');
        self::assertSame([1, 2], $window->candidatesOf([1, 2]));
        self::assertSame([4, 5, 6], $window->slice([1, 2, 3, 4, 5, 6, 7]));
        self::assertSame(7, $window->total([1, 2, 3, 4, 5, 6, 7]));
        self::assertNull(SearchWindow::of(0, 3, 10));
        self::assertNull(SearchWindow::of(5, 3, 10));
        $clamped = SearchWindow::of(1, 5000, 50000);
        self::assertSame([SearchWindow::MAX_PER_PAGE, SearchWindow::MAX_CANDIDATES], [$clamped?->perPage, $clamped?->candidates]);
        $low = SearchWindow::of(1, 0, 0);
        self::assertSame([1, 1, 1], [$low?->page, $low?->perPage, $low?->candidates]);
    }

    public function testSeeAllLinkCarriesTheTextAndAValidScope(): void
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $parameters): string => $route === 'evil' ? '//evil.example' : '/' . $route . '?' . http_build_query($parameters));

        self::assertSame('/tasks?board=1&q=a+b', SeeAllLink::build($urls, 'tasks', ['board' => 1], SearchQuery::forUserIdentifier('u', 'a b', 5)));
        self::assertSame('/tasks?q=x&scope=project%3A1', SeeAllLink::build($urls, 'tasks', [], SearchQuery::forUserIdentifier('u', 'x', 5, 'project:1'), 'q', 'scope'));
        self::assertSame('/tasks?q=x', SeeAllLink::build($urls, 'tasks', [], SearchQuery::forUserIdentifier('u', 'x', 5, 'bad scope'), 'q', 'scope'));
        self::assertNull(SeeAllLink::build($urls, 'evil', [], SearchQuery::forUserIdentifier('u', 'x', 5)));
    }
}
