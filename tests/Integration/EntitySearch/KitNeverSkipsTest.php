<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Integration\EntitySearch;

use Nowo\SearchKitBundle\Tests\Support\EntitySearch\DefaultHooksNoteContract;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\SkippedTest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

use function str_starts_with;

/**
 * 1.11.2: no contract test of the kit skips when the optional hooks keep their defaults, so hosts can run the kit
 * with `--fail-on-skipped`; every one records at least one assertion (never risky). Runs each kit method directly,
 * independent of the CLI flags of this suite.
 */
#[Group('database')]
final class KitNeverSkipsTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<DefaultHooksNoteContract>}>
     */
    public static function contracts(): iterable
    {
        yield 'every optional hook at its default' => [DefaultHooksNoteContractTest::class];
        yield 'no archived state' => [NoArchivedStateNoteContractTest::class];
    }

    /**
     * @param class-string<DefaultHooksNoteContract> $class
     */
    #[DataProvider('contracts')]
    public function testNoContractTestSkipsOrIsRisky(string $class): void
    {
        $ran = 0;
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if (!str_starts_with($name, 'testContract')) {
                continue;
            }
            $case   = new $class($name);
            $before = Assert::getCount();
            try {
                $case->{$name}();
            } catch (SkippedTest $skipped) {
                self::fail($name . ' was skipped with the optional hooks at their defaults: ' . $skipped->getMessage());
            }
            // Static assertions count on Assert, addToAssertionCount() on the case: PHPUnit adds both up.
            $performed = Assert::getCount() - $before + $case->numberOfAssertionsPerformed();
            self::assertGreaterThan(0, $performed, $name . ' performed no assertion (risky).');
            ++$ran;
        }

        self::assertGreaterThanOrEqual(25, $ran, "The kit's 25 contract tests (1.13.0) must all run.");
    }

    /**
     * 1.13.0: the filter hooks a contract test must implement once it declares filterKey() fail with a hint by default.
     */
    public function testFilterHooksFailWithAHintUntilImplemented(): void
    {
        $case  = new DefaultHooksNoteContractTest('testContractFiltersApplyInSqlBeforeTheTotal');
        $calls = [
            'seedForFilter'         => ['tenant-a', 'x', true],
            'matchingFilterValue'   => [],
            'seedHiddenFilterValue' => ['tenant-a', 'tenant-b', 'x'],
        ];
        foreach ($calls as $method => $arguments) {
            try {
                (new ReflectionMethod($case, $method))->invoke($case, ...$arguments);
                self::fail($method . '() must fail by default.');
            } catch (AssertionFailedError $failure) {
                self::assertStringContainsString('Override ' . $method . '()', $failure->getMessage());
            }
        }
    }
}
