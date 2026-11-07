<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\DependencyInjection;

use Nowo\SearchKitBundle\DependencyInjection\Configuration;
use Nowo\SearchKitBundle\Tests\Support\MinLengthExemptKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Filesystem\Filesystem;

final class MinLengthExemptPatternsConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . MinLengthExemptKernel::CACHE_ROOT);
    }

    public function testItIsEmptyByDefault(): void
    {
        self::assertSame([], (new Processor())->processConfiguration(new Configuration(), [[]])['min_length_exempt_patterns']);
    }

    public function testItKeepsValidPatterns(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['min_length_exempt_patterns' => ['^#\d+$', '^[A-Z]-\d+$']]]);
        self::assertSame(['^#\d+$', '^[A-Z]-\d+$'], $config['min_length_exempt_patterns']);
    }

    public function testAnInvalidPatternFailsWithTheEntryAndTheReason(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Invalid nowo_search_kit.min_length_exempt_patterns entry "^/\\\\s$": "\s" is not read alike');
        (new Processor())->processConfiguration(new Configuration(), [['min_length_exempt_patterns' => ['^/\s$']]]);
    }

    public function testANonStringEntryFails(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('entry 7: it must be a non-empty string');
        (new Processor())->processConfiguration(new Configuration(), [['min_length_exempt_patterns' => [7]]]);
    }

    public function testAnInvalidPatternFailsTheContainerCompilation(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('min_length_exempt_patterns');
        (new MinLengthExemptKernel(['min_length_exempt_patterns' => ['(?i)^#\d+$']]))->boot();
    }

    /** The value read at runtime would skip the check, so the option takes no env var at all. */
    public function testAnEnvValueFailsTheContainerCompilation(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('refuses %env()% values');
        (new MinLengthExemptKernel(['min_length_exempt_patterns' => ['%env(SEARCH_PATTERN)%']]))->boot();
    }
}
