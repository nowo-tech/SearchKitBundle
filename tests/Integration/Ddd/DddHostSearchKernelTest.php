<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Integration\Ddd;

use Nowo\SearchKitBundle\Contract\SearchEngineInterface;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Tests\Support\Ddd\DddHostKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function assert;
use function is_array;

use const JSON_THROW_ON_ERROR;

/**
 * DDD / hexagonal host (REQ-ARCH-001) in FrankenPHP worker mode without reset (REQ-RUNTIME-002): one kernel, no
 * services resetter, alice and bob in alternation. The host implements only the 1.7 ports; every answer holds only
 * that user's projects and scope, and memory stays flat.
 */
final class DddHostSearchKernelTest extends TestCase
{
    /** Iterations of the kept-alive loop (each: one search request, one page request, one engine call). */
    private const int ITERATIONS = 1200;

    /** Allowed memory growth after the warm-up. */
    private const int MEMORY_MARGIN = 4 * 1024 * 1024;

    private ?DddHostKernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-search-kit-bundle');
    }

    public function testTheHostPortsServeEachUserOnlyTheirOwnProjectsInOneKeptAliveKernel(): void
    {
        $this->kernel = new DddHostKernel();
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);
        self::assertFalse($container->has('services_resetter'), 'no reset between requests');
        $client = $container->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $engine = $container->get(SearchEngineInterface::class);
        assert($engine instanceof SearchEngineInterface);

        $expected = [
            'alice' => ['search' => ['Acme Shop', 'Beta Shop'], 'acme' => ['id' => 'acme', 'label' => 'Acme Shop'], 'gamma' => null],
            'bob'   => ['search' => ['Beta Shop', 'Gamma Lab'], 'acme' => null, 'gamma' => ['id' => 'gamma', 'label' => 'Gamma Lab']],
        ];

        $baseline = 0;
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $user = $i % 2 === 0 ? 'alice' : 'bob';
            // The firewall refreshes the user from the in-memory provider, so the password must match its (dummy) one.
            $client->loginUser(new InMemoryUser($user, 'x', ['ROLE_USER']));

            self::assertSame($expected[$user]['search'], $this->titles($client), $user . ' #' . $i);
            $project = $i % 4 < 2 ? 'acme' : 'gamma';
            self::assertSame($expected[$user][$project], $this->scope($client, $project), $user . ' on ' . $project . ' #' . $i);
            self::assertSame(
                $expected[$user]['search'],
                array_map(static fn (SearchItem $item): string => $item->title, array_merge(...array_map(static fn (SearchGroup $group): array => $group->items, $engine->searchFor($user, 'a')))),
                'engine by identifier, ' . $user,
            );

            if ($i === 19) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan($baseline + self::MEMORY_MARGIN, memory_get_usage(), 'memory stays flat over the kept-alive loop');

        $client->getCookieJar()->clear();
        $client->request(Request::METHOD_GET, '/search?q=a');
        self::assertSame(401, $client->getResponse()->getStatusCode(), 'guests are refused as before');
    }

    /**
     * @return list<string>
     */
    private function titles(KernelBrowser $client): array
    {
        $client->request(Request::METHOD_GET, '/search?q=a');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($data) && is_array($data['groups']));
        $titles = [];
        foreach ($data['groups'] as $group) {
            assert(is_array($group) && is_array($group['items']));
            foreach ($group['items'] as $item) {
                assert(is_array($item));
                $titles[] = (string) $item['title'];
            }
        }

        return $titles;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function scope(KernelBrowser $client, string $project): ?array
    {
        $crawler = $client->request(Request::METHOD_GET, '/projects/' . $project . '/page');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $config = json_decode((string) $crawler->filter('.nowo-search')->attr('data-nowo-search-config'), true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($config));
        $scope = $config['scope'] ?? null;
        assert($scope === null || is_array($scope));

        return $scope;
    }
}
