<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Functional;

use Nowo\SearchKitBundle\Tests\Support\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function assert;

use const JSON_THROW_ON_ERROR;

/**
 * GET /search: access, permission-aware groups, scope handling, limits, headers and the per-user rate limiter.
 */
final class SearchEndpointTest extends TestCase
{
    private ?TestKernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-search-kit-bundle');
    }

    public function testGuestsAndUsersWithoutTheRoleAreDenied(): void
    {
        $client = $this->client();
        $client->request(Request::METHOD_GET, '/search?q=shop');
        self::assertSame(401, $client->getResponse()->getStatusCode());
        self::assertSame('{"error":"unauthenticated"}', $client->getResponse()->getContent());

        $client = $this->client(['role' => 'ROLE_ADMIN']);
        $client->loginUser(new InMemoryUser('alice', 'x', ['ROLE_USER']));
        $client->request(Request::METHOD_GET, '/search?q=shop');
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame('{"error":"forbidden"}', $client->getResponse()->getContent());
    }

    public function testGroupsArePermissionAwareOrderedAndRendered(): void
    {
        $client = $this->client();
        $client->loginUser(new InMemoryUser('alice', 'x', ['ROLE_USER']));
        $data = $this->json($client, '/search?q=%20Shop%20');

        self::assertStringContainsString('no-store, private', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('noindex', $client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSame('Shop', $data['query']);
        self::assertSame(['projects', 'echo'], array_column($data['groups'], 'key'));

        $projects = $data['groups'][0];
        self::assertSame(13, $projects['total']);
        self::assertSame(['url' => '/projects?q=Shop', 'label' => 'See all'], $projects['more']);
        self::assertSame(['Acme Shop', 'Beta Shop', 'Gamma Shop'], array_column($projects['items'], 'title'));
        self::assertStringContainsString('<svg', (string) $projects['items'][0]['icon'], 'tabler:bug is in the icon set');
        self::assertStringContainsString('nowo-search__svg', (string) $projects['items'][0]['icon']);
        self::assertNull($projects['items'][1]['icon'], 'an unknown icon name falls back to the kind icon');
        self::assertSame(['label' => 'error', 'tone' => 'error'], $projects['items'][0]['badge']);
        self::assertSame('projects', $projects['items'][0]['kind']);

        $client->loginUser(new InMemoryUser('bob', 'x', ['ROLE_USER']));
        $data = $this->json($client, '/search?q=shop');
        self::assertSame(['Beta Shop'], array_column($data['groups'][0]['items'], 'title'), 'bob only sees beta');
    }

    public function testPagesComeFromTheMenusDeduplicatedWithABreadcrumb(): void
    {
        $client = $this->client();
        $client->loginUser(new InMemoryUser('alice', 'x', ['ROLE_USER']));
        $data  = $this->json($client, '/search?q=mess');
        $pages = $data['groups'][0];

        self::assertSame('pages', $pages['key']);
        self::assertSame('Pages', $pages['label']);
        self::assertSame(2, $pages['total']);
        self::assertSame(['/admin/messenger', '/admin/templates'], array_column($pages['items'], 'url'));
        self::assertSame('Main › Settings', $pages['items'][0]['subtitle']);
        self::assertNull($pages['items'][0]['icon'], 'tabler:file is not in the test icon set: the kind icon is used');
    }

    public function testScopeIsPassedOnAndAnInaccessibleScopeYieldsNothing(): void
    {
        $client = $this->client();
        $client->loginUser(new InMemoryUser('bob', 'x', ['ROLE_USER']));

        $data = $this->json($client, '/search?q=shop&scope=acme');
        self::assertSame(['echo'], array_column($data['groups'], 'key'), 'no project group, no 403, no hint');
        self::assertSame('acme', $data['groups'][0]['items'][0]['subtitle']);

        $data = $this->json($client, '/search?q=shop&scope=' . rawurlencode('../etc'));
        self::assertSame('unscoped', $data['groups'][1]['items'][0]['subtitle'], 'an invalid scope id is ignored');
    }

    #[DataProvider('shortQueries')]
    public function testShortQueriesReturnNoGroups(string $query): void
    {
        $client = $this->client();
        $client->loginUser(new InMemoryUser('alice', 'x', ['ROLE_USER']));
        self::assertSame([], $this->json($client, '/search?q=' . rawurlencode($query))['groups']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shortQueries(): iterable
    {
        yield 'empty' => [''];
        yield 'one character' => ['a'];
        yield 'blank' => ['   '];
    }

    public function testLongQueriesAreCutAndFailingProvidersSkipped(): void
    {
        $client = $this->client(['max_length' => 10]);
        $client->loginUser(new InMemoryUser('alice', 'x', ['ROLE_USER']));
        self::assertSame('abcdefghij', $this->json($client, '/search?q=' . str_repeat('abcdefghij', 5))['query']);
        self::assertSame([], $this->json($client, '/search?q=boom')['groups']);
    }

    public function testRateLimitAnswers429AndCanBeDisabled(): void
    {
        $client = $this->client(['rate_limit' => ['limit' => 2, 'interval' => '1 minute', 'policy' => 'fixed_window']]);
        $client->loginUser(new InMemoryUser('alice', 'x', ['ROLE_USER']));
        $client->request(Request::METHOD_GET, '/search?q=shop');
        $client->request(Request::METHOD_GET, '/search?q=shop');
        $client->request(Request::METHOD_GET, '/search?q=shop');
        self::assertSame(429, $client->getResponse()->getStatusCode());
        self::assertSame('{"error":"rate_limited"}', $client->getResponse()->getContent());
        self::assertStringContainsString('no-store, private', (string) $client->getResponse()->headers->get('Cache-Control'));

        $this->kernel?->shutdown();
        $client = $this->client(['rate_limit' => ['enabled' => false]]);
        $client->loginUser(new InMemoryUser('alice', 'x', ['ROLE_USER']));
        for ($i = 0; $i < 40; ++$i) {
            $client->request(Request::METHOD_GET, '/search?q=shop');
        }
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testTokenBucketPolicy(): void
    {
        $client = $this->client(['rate_limit' => ['limit' => 1, 'interval' => '1 minute', 'policy' => 'token_bucket']]);
        $client->loginUser(new InMemoryUser('alice', 'x', ['ROLE_USER']));
        $client->request(Request::METHOD_GET, '/search?q=shop');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->request(Request::METHOD_GET, '/search?q=shop');
        self::assertSame(429, $client->getResponse()->getStatusCode());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function client(array $config = []): KernelBrowser
    {
        $this->kernel = new TestKernel($config);
        $this->kernel->boot();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();

        return $client;
    }

    /**
     * @return array{query: string, groups: list<array<string, mixed>>}
     */
    private function json(KernelBrowser $client, string $url): array
    {
        $client->request(Request::METHOD_GET, $url);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        /** @var array{query: string, groups: list<array<string, mixed>>} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}
