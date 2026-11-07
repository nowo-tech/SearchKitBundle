<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Functional;

use Nowo\SearchKitBundle\Tests\Support\MinLengthExemptKernel;
use Nowo\SearchKitBundle\Tests\Support\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function assert;

use const JSON_THROW_ON_ERROR;

/**
 * GET /search and the rendered box with `min_length_exempt_patterns`: the exemption lifts `min_length` only.
 */
final class MinLengthExemptEndpointTest extends TestCase
{
    private const array EXEMPT = ['min_length' => 3, 'min_length_exempt_patterns' => ['^#\d+$']];

    private MinLengthExemptKernel|TestKernel|null $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $filesystem = new Filesystem();
        $filesystem->remove(sys_get_temp_dir() . MinLengthExemptKernel::CACHE_ROOT);
        $filesystem->remove(sys_get_temp_dir() . '/nowo-search-kit-bundle');
    }

    public function testWithoutPatternsAShortQueryReachesNoProvider(): void
    {
        $client = $this->signedIn(['min_length' => 3]);
        self::assertSame([], $this->json($client, '/search?q=%237')['groups']);
    }

    public function testAShortMatchReachesOnlyTheProvidersThatOptedIn(): void
    {
        $client = $this->signedIn(self::EXEMPT);
        $data   = $this->json($client, '/search?q=%20%237%20');

        self::assertSame('#7', $data['query']);
        self::assertSame(['number'], array_column($data['groups'], 'key'), 'the other provider never scans for it');
        self::assertSame('below', $data['groups'][0]['items'][0]['subtitle']);
    }

    public function testAShortQueryMatchingNoPatternStaysRefused(): void
    {
        $client = $this->signedIn(self::EXEMPT);
        foreach (['ab', '%23', '7', '%23%D9%A7', ''] as $query) {
            self::assertSame([], $this->json($client, '/search?q=' . $query)['groups'], $query);
        }
    }

    public function testAQueryAtMinLengthStillReachesEveryProvider(): void
    {
        $client = $this->signedIn(self::EXEMPT);
        $data   = $this->json($client, '/search?q=%2377');

        self::assertSame(['number', 'plain'], array_column($data['groups'], 'key'));
        self::assertSame('normal', $data['groups'][0]['items'][0]['subtitle']);
    }

    public function testThePatternThatMatchesTheEmptyQueryDoesNotExemptIt(): void
    {
        $client = $this->signedIn(['min_length' => 3, 'min_length_exempt_patterns' => ['^\d*$']]);
        self::assertSame([], $this->json($client, '/search?q=%20')['groups']);
        self::assertSame(['number'], array_column($this->json($client, '/search?q=7')['groups'], 'key'));
    }

    public function testTheMaximumLengthStillCutsAnExemptQuery(): void
    {
        $client = $this->signedIn(['min_length' => 50, 'max_length' => 10, 'min_length_exempt_patterns' => ['^#\d+$']]);
        $data   = $this->json($client, '/search?q=%23' . str_repeat('1', 30));

        self::assertSame('#' . str_repeat('1', 9), $data['query']);
        self::assertSame('#' . str_repeat('1', 9), $data['groups'][0]['items'][0]['title']);
    }

    public function testTheRateLimitStillCountsExemptQueries(): void
    {
        $client = $this->signedIn([...self::EXEMPT, 'rate_limit' => ['limit' => 1, 'interval' => '1 minute', 'policy' => 'fixed_window']]);
        $this->json($client, '/search?q=%237');
        $client->request(Request::METHOD_GET, '/search?q=%237');

        self::assertSame(429, $client->getResponse()->getStatusCode());
        self::assertSame('{"error":"rate_limited"}', $client->getResponse()->getContent());
    }

    public function testAccessChecksStillApplyToExemptQueries(): void
    {
        $client = $this->client(self::EXEMPT);
        $client->request(Request::METHOD_GET, '/search?q=%237');
        self::assertSame(401, $client->getResponse()->getStatusCode());

        $this->kernel?->shutdown();
        $client = $this->signedIn([...self::EXEMPT, 'role' => 'ROLE_ADMIN']);
        $client->request(Request::METHOD_GET, '/search?q=%237');
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testTheBrowserGetsThePatternsOnlyWhenConfigured(): void
    {
        self::assertSame(['^#\d+$'], $this->browserConfig(self::EXEMPT)['minLengthExemptPatterns'] ?? null);

        $this->kernel?->shutdown();
        $default = $this->browserConfig([]);
        self::assertArrayNotHasKey('minLengthExemptPatterns', $default, 'absent, not empty: the attribute stays byte-identical');
        self::assertSame(['url', 'minLength', 'maxLength', 'debounceMs', 'shortcuts', 'scope', 'actions', 'recent', 'assets', 'popoverCss', 'labels'], array_keys($default));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function browserConfig(array $config): array
    {
        $this->kernel = new TestKernel($config);
        $this->kernel->boot();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $client->loginUser(new InMemoryUser('bob', 'x', ['ROLE_USER']));
        $crawler = $client->request(Request::METHOD_GET, '/page');

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $crawler->filter('.nowo-search')->attr('data-nowo-search-config'), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function signedIn(array $config): KernelBrowser
    {
        $client = $this->client($config);
        $client->loginUser(new InMemoryUser('alice', 'x', ['ROLE_USER']));

        return $client;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function client(array $config): KernelBrowser
    {
        $this->kernel = new MinLengthExemptKernel($config);
        $this->kernel->boot();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();

        return $client;
    }

    /**
     * @return array{query: string, groups: list<array{key: string, items: list<array{title: string, subtitle: string}>}>}
     */
    private function json(KernelBrowser $client, string $url): array
    {
        $client->request(Request::METHOD_GET, $url);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        /** @var array{query: string, groups: list<array{key: string, items: list<array{title: string, subtitle: string}>}>} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}
