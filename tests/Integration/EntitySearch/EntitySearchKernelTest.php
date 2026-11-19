<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Integration\EntitySearch;

use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Search\Recent\RecentResolver;
use Nowo\SearchKitBundle\Search\SearchEngine;
use Nowo\SearchKitBundle\Search\SearchRequest;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\KernelNoteDefinition;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\NoteDatabase;
use Nowo\SearchKitBundle\Tests\Support\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_map;
use function bin2hex;
use function random_bytes;

/**
 * 1.9.0 in a real kernel: definitions become providers (EntitySearchPass), `groups.<key>.enabled` / `priority` apply
 * to every provider, the results page pages the group, and revalidated recents resolve through the same checks.
 */
#[Group('database')]
final class EntitySearchKernelTest extends TestCase
{
    private string $marker;

    protected function setUp(): void
    {
        $this->marker = 'skk' . bin2hex(random_bytes(6));
        NoteDatabase::get()->insert(KernelNoteDefinition::TENANT, $this->marker . ' shop note');
        NoteDatabase::get()->insert('tenant-a', $this->marker . ' stub shop');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-search-kit-bundle');
    }

    public function testDefinitionsBecomeProvidersAndPagesAndRecentsWork(): void
    {
        $kernel = new TestKernel(['recent' => ['revalidate' => true], 'groups' => ['echo' => ['enabled' => false]]], true, null, TestKernel::SCOPE_CONTEXT, false, true);
        $kernel->boot();

        try {
            $container = $kernel->getContainer();
            self::assertTrue($container->getParameter('nowo_search_kit.recent.safe'));
            $engine = $container->get(SearchEngine::class);
            self::assertInstanceOf(SearchEngine::class, $engine);
            $alice = new InMemoryUser('alice', null);

            self::assertSame(['kernel_notes', 'kernel_stub'], $this->keys($engine->search($alice, $this->marker)));
            $notes = $engine->search($alice, $this->marker)[0];
            self::assertSame('/page?q=' . $this->marker, $notes->moreUrl);
            self::assertSame('See all', $notes->moreLabel);
            self::assertSame(['kernel_stub'], $this->keys($engine->search(new InMemoryUser('bob', null), $this->marker)), 'Bob has no tenant: no notes group.');

            self::assertTrue($engine->isPaginated('kernel_notes'));
            $page = $engine->page(new SearchRequest($this->marker, $alice), 'kernel_notes', 1, 20);
            self::assertInstanceOf(SearchGroup::class, $page);
            self::assertSame($this->marker . ' shop note', $page->items[0]->title);

            $recents = $container->get(RecentResolver::class);
            self::assertInstanceOf(RecentResolver::class, $recents);
            $url  = $notes->items[0]->url;
            $kept = $recents->resolve([['url' => $url, 'kind' => 'kernel_notes'], ['url' => '/kernel-notes/999999999', 'kind' => 'kernel_notes']], 'alice', 'en', 8);
            self::assertSame([$url], array_map(static fn ($item): string => $item->url, $kept));
            self::assertSame([], $recents->resolve([['url' => $url, 'kind' => 'kernel_notes']], 'bob', 'en', 8));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testGroupsCanBeSwitchedOffAndReordered(): void
    {
        $kernel = new TestKernel([
            'recent' => ['enabled' => true, 'revalidate' => false],
            'groups' => ['kernel_stub' => ['enabled' => false], 'kernel_notes' => ['priority' => 500], 'echo' => ['priority' => 600]],
        ], true, null, TestKernel::SCOPE_CONTEXT, false, true);
        $kernel->boot();

        try {
            $container = $kernel->getContainer();
            self::assertFalse($container->getParameter('nowo_search_kit.recent.safe'));
            $engine = $container->get(SearchEngine::class);
            self::assertInstanceOf(SearchEngine::class, $engine);
            $alice = new InMemoryUser('alice', null);

            self::assertSame(['echo', 'kernel_notes'], $this->keys($engine->search($alice, $this->marker)), 'Stub off; sorted by priority.');
            self::assertSame(['echo', 'projects'], $this->keys($engine->search($alice, 'acme')), 'A group without a priority counts as 0.');
            self::assertFalse($engine->isPaginated('kernel_stub'));
            self::assertNull($engine->page(new SearchRequest($this->marker, $alice), 'kernel_stub', 1, 20));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testDisabledNonPaginatedGroupIsDropped(): void
    {
        $kernel = new TestKernel(['groups' => ['projects' => ['enabled' => false], 'echo' => ['enabled' => false]]], true, null, TestKernel::SCOPE_CONTEXT, false, true);
        $kernel->boot();

        try {
            $engine = $kernel->getContainer()->get(SearchEngine::class);
            self::assertInstanceOf(SearchEngine::class, $engine);

            self::assertSame([], $this->keys($engine->search(new InMemoryUser('alice', null), 'acme')));
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidGroupKeys(): iterable
    {
        yield 'space' => ['Bad Key'];
        yield 'digit first' => ['1tasks'];
    }

    #[DataProvider('invalidGroupKeys')]
    public function testGroupKeysAreValidated(string $key): void
    {
        $kernel = new TestKernel(['groups' => [$key => ['enabled' => false]]]);

        $this->expectException(InvalidConfigurationException::class);
        $kernel->boot();
    }

    /**
     * @param list<SearchGroup> $groups
     *
     * @return list<string>
     */
    private function keys(array $groups): array
    {
        return array_map(static fn (SearchGroup $group): string => $group->key, $groups);
    }
}
