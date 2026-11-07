<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\Search;

use Nowo\SearchKitBundle\Search\MinLengthExemptPattern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function ini_get;

use const JSON_THROW_ON_ERROR;

/**
 * The fixture is shared with `min-length.test.ts`: both dialects have to answer the same for each pair.
 */
final class MinLengthExemptPatternTest extends TestCase
{
    /**
     * @return array{valid: list<array{pattern: string, match: list<string>, noMatch: list<string>}>, invalid: list<string>}
     */
    private static function fixture(): array
    {
        /** @var array{valid: list<array{pattern: string, match: list<string>, noMatch: list<string>}>, invalid: list<string>} $fixture */
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/min-length-exempt-patterns.json'), true, 512, JSON_THROW_ON_ERROR);

        return $fixture;
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function validPatterns(): iterable
    {
        foreach (self::fixture()['valid'] as $case) {
            yield $case['pattern'] => [$case['pattern'], $case['match'], $case['noMatch']];
        }
    }

    /**
     * @param list<string> $match
     * @param list<string> $noMatch
     */
    #[DataProvider('validPatterns')]
    public function testAValidPatternMeansTheSameAsInJavaScript(string $pattern, array $match, array $noMatch): void
    {
        self::assertNull(MinLengthExemptPattern::problem($pattern));
        foreach ($match as $text) {
            self::assertTrue(MinLengthExemptPattern::matches($pattern, $text), json_encode($text, JSON_THROW_ON_ERROR));
        }
        foreach ($noMatch as $text) {
            self::assertFalse(MinLengthExemptPattern::matches($pattern, $text), json_encode($text, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPatterns(): iterable
    {
        foreach (self::fixture()['invalid'] as $pattern) {
            yield 'pattern ' . json_encode($pattern, JSON_THROW_ON_ERROR) => [$pattern];
        }
    }

    #[DataProvider('invalidPatterns')]
    public function testAPatternOutsideTheSharedSubsetIsRefused(string $pattern): void
    {
        self::assertNotNull(MinLengthExemptPattern::problem($pattern));
    }

    public function testNonStringsAndInvalidUtf8AreRefused(): void
    {
        foreach ([null, 7, true, []] as $value) {
            self::assertSame('it must be a non-empty string', MinLengthExemptPattern::problem($value));
        }
        self::assertSame('it is not valid UTF-8', MinLengthExemptPattern::problem("\xFF"));
    }

    public function testTheReasonNamesTheConstruct(): void
    {
        self::assertStringContainsString('"\s"', (string) MinLengthExemptPattern::problem('^\s$'));
        self::assertStringContainsString('line breaks', (string) MinLengthExemptPattern::problem('.'));
        self::assertStringContainsString('server locale', (string) MinLengthExemptPattern::problem('^\w$'));
        self::assertStringContainsString('repeat of zero', (string) MinLengthExemptPattern::problem('(?:|^){0}a'));
        self::assertStringContainsString('does not compile', (string) MinLengthExemptPattern::problem('(a'));
    }

    public function testInvalidUtf8TextNeverMatches(): void
    {
        self::assertFalse(MinLengthExemptPattern::matches('^\D$', "\xFF"));
    }

    public function testAnEngineErrorIsNotAMatch(): void
    {
        $limit = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1');

        try {
            self::assertNull(MinLengthExemptPattern::matches('^(?:a+)+$', str_repeat('a', 40) . 'b'));
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
        }
    }
}
