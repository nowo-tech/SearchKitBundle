<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Unit\Everywhere;

use ArrayIterator;
use Generator;
use Nowo\SearchKitBundle\Contract\SearchAccessCheckerInterface;
use Nowo\SearchKitBundle\Contract\SearchActionProviderInterface;
use Nowo\SearchKitBundle\Contract\SearchPageSourceInterface;
use Nowo\SearchKitBundle\Contract\SearchReferenceResolverInterface;
use Nowo\SearchKitBundle\Model\SearchAccessRequest;
use Nowo\SearchKitBundle\Model\SearchAction;
use Nowo\SearchKitBundle\Model\SearchContext;
use Nowo\SearchKitBundle\Model\SearchGroup;
use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchPage;
use Nowo\SearchKitBundle\Model\SearchQuery;
use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Model\SearchTerm;
use Nowo\SearchKitBundle\Search\Access\SearchAccess;
use Nowo\SearchKitBundle\Search\Action\ActionCollector;
use Nowo\SearchKitBundle\Search\Page\PageRegistryProvider;
use Nowo\SearchKitBundle\Search\Recent\RecentResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The page registry (access before matching, scope rules, cross-locale keywords, bounded candidates), the action
 * collector and the recents resolver.
 */
final class RegistryActionsRecentTest extends TestCase
{
    /** @var list<array{id: string, label: string, translation_domain: string|null, route: string, route_parameters: array<string, scalar|null>, trail: list<string>, icon: string|null, keywords: string|null, scope: string, attribute: string|null, subject: string|null, scope_parameter: string|null}> */
    private const array PAGES = [
        ['id' => 'profile', 'label' => 'page.profile', 'translation_domain' => 'messages', 'route' => 'settings', 'route_parameters' => ['section' => 'profile'], 'trail' => ['crumb.settings'], 'icon' => null, 'keywords' => 'page.profile.kw', 'scope' => 'none', 'attribute' => 'EDIT', 'subject' => 'me', 'scope_parameter' => null],
        ['id' => 'files', 'label' => 'Files', 'translation_domain' => null, 'route' => 'tab', 'route_parameters' => ['tab' => 'files'], 'trail' => [], 'icon' => 'tabler:folder', 'keywords' => 'documents, attachments', 'scope' => 'required', 'attribute' => null, 'subject' => null, 'scope_parameter' => 'id'],
        ['id' => 'nokey', 'label' => 'Untranslated', 'translation_domain' => 'messages', 'route' => 'page', 'route_parameters' => [], 'trail' => [], 'icon' => null, 'keywords' => 'missing.key', 'scope' => 'none', 'attribute' => null, 'subject' => null, 'scope_parameter' => null],
        ['id' => 'noparam', 'label' => 'Scoped without parameter', 'translation_domain' => null, 'route' => 'page', 'route_parameters' => [], 'trail' => [], 'icon' => null, 'keywords' => null, 'scope' => 'required', 'attribute' => null, 'subject' => null, 'scope_parameter' => null],
    ];

    public function testAccessIsCheckedBeforeMatchingAndTheTotalIsExact(): void
    {
        $checker  = Fixtures::checker(static fn (SearchAccessRequest $request): bool => $request->url !== '/page');
        $registry = $this->registry([$checker]);
        self::assertTrue(property_exists($checker, 'asked'));

        $group = $registry->search($this->query('avatar'));
        self::assertNotNull($group);
        self::assertTrue($group->exactTotal);
        self::assertSame(1, $group->total);
        $row = $group->items[0];
        self::assertSame(['Profile', '/settings/profile', 'Settings', 'tabler:file'], [$row->title, $row->url, $row->subtitle, $row->icon]);
        self::assertNotNull($row->score);

        // Every candidate was checked, matching or not, with its attribute and subject.
        /** @var list<SearchAccessRequest> $requests */
        $requests = $checker->asked;
        $asked    = array_map(static fn (SearchAccessRequest $r): string => $r->url . '|' . ($r->attribute ?? '-') . '|' . ($r->subject ?? '-'), $requests);
        self::assertSame(['/settings/profile|EDIT|me', '/page|-|-'], $asked);

        self::assertNull($registry->search($this->query('Untranslated')), 'denied: never matched');
        self::assertNull($this->registry([])->search($this->query('avatar')), 'no checker: nothing');
    }

    public function testKeywordsAcrossLocalesAndScopedEntries(): void
    {
        $registry = $this->registry([Fixtures::checker(static fn (): bool => true)]);
        self::assertSame(['Profile'], $this->titles($registry->search($this->query('contraseña'))), 'Spanish keywords for an English viewer');
        self::assertSame(['Perfil'], $this->titles($registry->search($this->query('password', 'es'))), 'English keywords for a Spanish viewer, label in Spanish');
        self::assertSame(['Profile'], $this->titles($registry->search($this->query('perfil'))), 'a label of another locale is a keyword');
        self::assertNull($registry->search($this->query('missing.key')), 'a keywords key without message never matches itself');
        self::assertNull($registry->search($this->query('documents')), 'scope required: not listed unscoped');
        self::assertNull($registry->search($this->query('avatar', null, 'acme')), 'scope none: not listed while scoped');
        $files = $registry->search($this->query('documents', null, 'acme'));
        self::assertSame(['/projects/acme/tabs/files'], array_map(static fn (SearchItem $i): string => $i->url, $files->items ?? []));
        self::assertSame('tabler:folder', $files?->items[0]->icon);
    }

    public function testKeywordsAreCappedPerLocaleAndAcrossLocales(): void
    {
        $words = static fn (string $prefix, int $count): string => implode(', ', array_map(static fn (int $i): string => $prefix . $i, range(1, $count)));
        // es repeats an English keyword (an untranslated one): kept once.
        $messages = ['en' => ['kw' => $words('alpha', 40)], 'es' => ['kw' => 'alpha1, ' . $words('beta', 31)], 'it' => ['kw' => $words('gamma', 32)], 'fr' => ['kw' => $words('delta', 32)]];
        $page     = [['id' => 'p', 'label' => 'Page', 'translation_domain' => 'messages', 'route' => 'page', 'route_parameters' => [], 'trail' => [], 'icon' => null, 'keywords' => 'kw', 'scope' => 'none', 'attribute' => null, 'subject' => null, 'scope_parameter' => null]];
        $registry = new PageRegistryProvider($page, [], Fixtures::access(), Fixtures::router(), Fixtures::translator($messages), ['en', 'es', 'it', 'fr']);
        self::assertNotNull($registry->search($this->query('alpha32')));
        self::assertNull($registry->search($this->query('alpha33')), 'at most 32 keywords per locale');
        self::assertNotNull($registry->search($this->query('gamma32')));
        self::assertNotNull($registry->search($this->query('delta1')), 'the duplicate left room for one more');
        self::assertNull($registry->search($this->query('delta2')), 'at most 64 keywords of the other locales');
    }

    public function testTheEvaluationCeilingStopsTheScanAndSaysSo(): void
    {
        $logger = Fixtures::logger();
        $pages  = [];
        for ($i = 0; $i < 400; ++$i) {
            $pages[] = ['id' => 'p' . $i, 'label' => 'Entry ' . $i, 'translation_domain' => null, 'route' => 'issue', 'route_parameters' => ['id' => $i], 'trail' => [], 'icon' => null, 'keywords' => implode(', ', range(1, 32)), 'scope' => 'none', 'attribute' => null, 'subject' => null, 'scope_parameter' => null];
        }
        $registry = new PageRegistryProvider($pages, [], Fixtures::access(), Fixtures::router(), Fixtures::translator(), ['en'], 500, 'tabler:file', $logger);
        $terms    = array_map(static fn (int $i): SearchTerm => new SearchTerm('alt' . $i, 'en', true), range(1, 24));
        // A typo on every label: each entry costs its fuzzy comparisons (FUZZY_COST each) until the ceiling.
        $group = $registry->search(new SearchQuery('entrx', null, 5, null, false, 'alice', [], 'en', $terms, true));
        self::assertNotNull($group);
        self::assertLessThan(400, $group->total, 'entries past the ceiling are not listed');
        self::assertSame(['notice: Search page registry stopped at its evaluation ceiling (100000).'], $logger->lines);
    }

    public function testTheCeilingAlsoBoundsTheExactPass(): void
    {
        $logger = Fixtures::logger();
        $pages  = [];
        for ($i = 0; $i < 400; ++$i) {
            // Every word of the query occurs inside the keywords, never as a word start: the pre-filter passes and
            // every text is compared, without a match.
            $pages[] = ['id' => 'p' . $i, 'label' => 'Entry ' . $i, 'translation_domain' => null, 'route' => 'issue', 'route_parameters' => ['id' => $i], 'trail' => [], 'icon' => null, 'keywords' => implode(', ', array_map(static fn (int $k): string => 'xabx' . $k . 'xcdx', range(1, 32))), 'scope' => 'none', 'attribute' => null, 'subject' => null, 'scope_parameter' => null];
        }
        $registry = new PageRegistryProvider($pages, [], Fixtures::access(), Fixtures::router(), Fixtures::translator(), ['en'], 500, 'tabler:file', $logger);
        $terms    = array_fill(0, 24, new SearchTerm('ab cd', 'en', true));
        self::assertNull($registry->search(new SearchQuery('ab cd', null, 5, null, false, 'alice', [], 'en', $terms)));
        self::assertSame(['notice: Search page registry stopped at its evaluation ceiling (100000).'], $logger->lines);
    }

    public function testSourcesAreBoundedAndAFailingSourceIsSkipped(): void
    {
        $logger  = Fixtures::logger();
        $sources = [
            new class implements SearchPageSourceInterface {
                public function pages(SearchQuery $query): iterable
                {
                    yield new SearchPage('a', 'Alpha tab', 'tab', ['tab' => 'a'], null, [], null, null, SearchPage::SCOPE_REQUIRED, null, null, 'id');
                    yield new SearchPage('a2', 'Alpha tab', 'tab', ['tab' => 'a'], null, [], null, null, SearchPage::SCOPE_REQUIRED, null, null, 'id');

                    throw new RuntimeException('source down');
                }
            },
            new class implements SearchPageSourceInterface {
                public function pages(SearchQuery $query): iterable
                {
                    return [new SearchPage('b', 'Alpha bad route', 'missing_route', [], null)];
                }
            },
        ];
        $registry = new PageRegistryProvider([], $sources, Fixtures::access(), Fixtures::router(), Fixtures::translator(), ['en'], 500, 'tabler:file', $logger);
        self::assertSame(['Alpha tab'], $this->titles($registry->search($this->query('alpha', null, 'x'))), 'deduplicated by URL');
        self::assertSame(['error: Search page source ' . $sources[0]::class . ' failed: RuntimeException.'], $logger->lines);

        $one = new PageRegistryProvider([], $sources, Fixtures::access(), Fixtures::router(), Fixtures::translator(), ['en'], 1, 'tabler:file', $logger);
        self::assertSame(['Alpha tab'], $this->titles($one->search($this->query('alpha', null, 'x'))));
        self::assertCount(1, $logger->lines, 'the source stopped before its failure');

        $bounded = new PageRegistryProvider(self::PAGES, $sources, Fixtures::access(), Fixtures::router(), Fixtures::translator(), ['en'], 1);
        self::assertNull($bounded->search($this->query('alpha', null, 'x')), 'max_candidates stops before the sources');
    }

    public function testRecentReferencesResolveThroughTheRegistry(): void
    {
        $registry = $this->registry([Fixtures::checker(static fn (): bool => true)]);
        $profile  = $registry->resolve(new SearchReference('/settings/profile', 'pages', 'settings', ['section' => 'profile']), 'alice', 'es');
        self::assertSame(['Perfil', 'Ajustes'], [$profile?->title, $profile?->subtitle]);
        self::assertNull($profile?->score);
        self::assertSame('Files', $registry->resolve(new SearchReference('/projects/acme/tabs/files', 'pages', 'tab', ['id' => 'acme', 'tab' => 'files']), 'alice', null)?->title);
        self::assertNull($registry->resolve(new SearchReference('/projects/acme/tabs/files', 'pages', 'tab', ['tab' => 'files']), 'alice', null), 'no scope id');
        self::assertNull($registry->resolve(new SearchReference('/settings/other', 'pages', 'settings', ['section' => 'other']), 'alice', null));
        self::assertNull($registry->resolve(new SearchReference('/x', 'pages', 'unknown'), 'alice', null));
        self::assertNull($this->registry([])->resolve(new SearchReference('/settings/profile', 'pages', 'settings', ['section' => 'profile']), 'alice', null), 'denied now: gone');
    }

    public function testActionsAreAccessCheckedBoundedAndFallBackToQuickActions(): void
    {
        $logger    = Fixtures::logger();
        $providers = [
            new class implements SearchActionProviderInterface {
                public function actions(SearchContext $context, ?string $scope): iterable
                {
                    yield new SearchAction('Create issue', '/page', ['nueva incidencia'], null, 'c');
                    yield new SearchAction('Denied', '/settings/x');
                    yield new SearchAction('External', 'https://example.com/x');
                    yield new SearchAction('Also', '/page');
                }
            },
            new class implements SearchActionProviderInterface {
                public function actions(SearchContext $context, ?string $scope): iterable
                {
                    throw new RuntimeException('down');
                }
            },
        ];
        $collector = new ActionCollector($providers, Fixtures::access(['/settings/x']), [], Fixtures::router(), Fixtures::translator(), null, 3, $logger);
        $context   = new SearchContext('alice', '/page');
        self::assertTrue($collector->hasProviders());
        self::assertSame(['Create issue', 'External'], array_map(static fn (SearchAction $a): string => $a->label, $collector->forContext($context, null)), 'at most max_candidates read (denied ones count)');
        self::assertSame([], $logger->lines, 'the bound stopped before the failing provider');
        $collector = new ActionCollector($providers, Fixtures::access(['/settings/x', '/page']), [], Fixtures::router(), Fixtures::translator(), null, 200, $logger);
        self::assertSame(['External'], array_map(static fn (SearchAction $a): string => $a->label, $collector->forContext($context, null)));
        $collector = new ActionCollector($providers, Fixtures::access(['/settings/x']), [], Fixtures::router(), Fixtures::translator(), null, 3, $logger);
        self::assertStringContainsString('failed: RuntimeException', implode("\n", $logger->lines));
        self::assertSame([['title' => 'Create issue', 'subtitle' => '', 'url' => '/page', 'kind' => 'action', 'shortcut' => 'c'], ['title' => 'External', 'subtitle' => '', 'url' => 'https://example.com/x', 'kind' => 'action']], $collector->emptyQueryRows($context, null));

        $matched = $collector->search($this->query('incidencia'), $context, true, 5);
        self::assertSame(['Create issue'], $this->titles($matched));
        self::assertNotNull($matched);
        self::assertSame('Actions', $matched->label);
        self::assertNotNull($matched->items[0]->score);
        self::assertCount(2, $this->titles($collector->search($this->query(''), $context, true, 5)));
        self::assertCount(1, $this->titles($collector->search($this->query(''), $context, true, 1)));
        self::assertNull($collector->search($this->query('zzz'), $context, true, 5));

        $quick = [
            ['label' => 'page.profile', 'translation_domain' => 'messages', 'route' => 'page', 'route_parameters' => [], 'url' => null, 'kind' => 'action', 'role' => null],
            ['label' => 'Admin', 'translation_domain' => 'messages', 'route' => null, 'route_parameters' => [], 'url' => '/admin', 'kind' => 'page', 'role' => 'ROLE_ADMIN'],
            ['label' => 'Evil', 'translation_domain' => 'messages', 'route' => null, 'route_parameters' => [], 'url' => 'javascript:x', 'kind' => 'page', 'role' => null],
        ];
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(true);
        $fallback = new ActionCollector(new ArrayIterator([]), Fixtures::access(), $quick, Fixtures::router(), Fixtures::translator(), $security);
        self::assertFalse($fallback->hasProviders());
        self::assertSame(['Profile', 'Admin', 'Evil'], array_column($fallback->emptyQueryRows($context, null), 'title'));
        self::assertSame(['Profile', 'Evil'], array_column($fallback->quickActionRows(false), 'title'), 'no role check possible: role-gated rows are skipped');
        self::assertSame(['Profile', 'Admin'], $this->titles($fallback->search($this->query(''), $context, true, 5)), 'unsafe URLs never become rows');
        self::assertSame(['Profile'], $this->titles($fallback->search($this->query('prof'), $context, false, 5)));
        self::assertSame(['Profile', 'Evil'], array_column((new ActionCollector([], Fixtures::access(), $quick, Fixtures::router(), Fixtures::translator()))->quickActionRows(true), 'title'), 'no Security service: role-gated rows are skipped');
        $generator = (static function () use ($providers): Generator {
            yield $providers[0];
        })();
        self::assertFalse((new ActionCollector([], Fixtures::access(), [], Fixtures::router(), Fixtures::translator()))->hasProviders());
        self::assertTrue((new ActionCollector($generator, Fixtures::access(), [], Fixtures::router(), Fixtures::translator()))->hasProviders(), 'a plain iterable is read lazily');
    }

    public function testRecentsKeepOnlyAllowedAndResolvedReferences(): void
    {
        $logger    = Fixtures::logger();
        $resolvers = [
            new class implements SearchReferenceResolverInterface {
                public function resolve(SearchReference $reference, string $userIdentifier, ?string $locale): ?SearchItem
                {
                    if ($reference->url === '/issues/9') {
                        throw new RuntimeException('down');
                    }

                    return match ($reference->url) {
                        '/issues/1' => new SearchItem('Issue 1', '/issues/1', 'Acme'),
                        '/issues/2' => new SearchItem('Wrong URL', '/issues/3'),
                        default     => null,
                    };
                }
            },
            new class implements SearchReferenceResolverInterface {
                public function resolve(SearchReference $reference, string $userIdentifier, ?string $locale): ?SearchItem
                {
                    return $reference->url === '/issues/9' ? new SearchItem('Issue 9', '/issues/9', '', null, null, $reference->kind) : null;
                }
            },
        ];
        $resolver = new RecentResolver(Fixtures::access(['/issues/4']), $resolvers, $logger);
        $refs     = [
            ['url' => '/issues/1', 'kind' => 'issues'],
            ['url' => '/issues/1', 'kind' => 'issues'],
            ['url' => '/issues/2'],
            ['url' => '/issues/4'],
            ['url' => '/issues/9', 'kind' => 'BAD kind!'],
            ['url' => '/nowhere'],
            ['url' => 'https://example.com/issues/1'],
            ['url' => '/' . str_repeat('a', 3000)],
            ['kind' => 'x'],
            'junk',
        ];
        $items = $resolver->resolve($refs, 'alice', 'en', 8);
        self::assertSame(['/issues/1', '/issues/9'], array_map(static fn (SearchItem $i): string => $i->url, $items));
        self::assertSame('page', $items[1]->kind, 'an unknown kind becomes page');
        self::assertCount(1, $logger->lines);
        self::assertSame(['/issues/1'], array_map(static fn (SearchItem $i): string => $i->url, $resolver->resolve($refs, 'alice', 'en', 1)), 'at most `max` references are read');
    }

    /**
     * @param list<SearchAccessCheckerInterface> $checkers
     */
    private function registry(array $checkers): PageRegistryProvider
    {
        return new PageRegistryProvider(self::PAGES, [], new SearchAccess($checkers, Fixtures::router()), Fixtures::router(), Fixtures::translator(), ['en', 'es']);
    }

    private function query(string $text, ?string $locale = null, ?string $scope = null): SearchQuery
    {
        return new SearchQuery($text, null, 5, $scope, false, 'alice', [], $locale);
    }

    /**
     * @return list<string>
     */
    private function titles(?SearchGroup $group): array
    {
        return array_map(static fn (SearchItem $item): string => $item->title, $group->items ?? []);
    }
}
