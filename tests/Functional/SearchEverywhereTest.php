<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Functional;

use Nowo\SearchKitBundle\Tests\Support\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function assert;
use function count;
use function is_array;
use function is_string;

use const JSON_THROW_ON_ERROR;

/**
 * Spec 240 "search everywhere" through HTTP: the permission matrix (alice, bob, admin) and the anti-leak rules —
 * counts, snippets, recents and filter probing — on the registry, the actions, the filters, the results page and the
 * recents revalidation.
 *
 * Fixtures: alice is a member of acme + beta, bob of beta, admin of nothing (ROLE_ADMIN); the project gamma and its
 * issue #5 belong to nobody here and must never surface (tests/Support/Everywhere).
 */
final class SearchEverywhereTest extends TestCase
{
    /** The 1.8.0 configuration of the "everywhere" host. */
    public const array CONFIG = [
        'per_group' => 2,
        'pages'     => [
            ['id' => 'settings.profile', 'label' => 'test.page.profile', 'route' => 'test_settings', 'route_parameters' => ['section' => 'profile'], 'trail' => ['test.crumb.settings'], 'keywords' => 'test.page.profile_keywords', 'icon' => 'tabler:user'],
            ['id' => 'settings.billing', 'label' => 'test.page.billing', 'route' => 'test_settings', 'route_parameters' => ['section' => 'billing'], 'keywords' => 'test.page.billing_keywords'],
            ['id' => 'settings.language', 'label' => 'test.page.language', 'route' => 'test_settings', 'route_parameters' => ['section' => 'language'], 'keywords' => 'test.page.language_keywords'],
            ['id' => 'admin', 'label' => 'Admin console', 'translation_domain' => null, 'route' => 'test_admin', 'keywords' => 'secret-admin-keyword, control room'],
            ['id' => 'project.members', 'label' => 'Members', 'translation_domain' => null, 'route' => 'test_project_tab', 'route_parameters' => ['tab' => 'members'], 'scope' => 'required', 'scope_parameter' => 'id', 'keywords' => 'team'],
            ['id' => 'broken', 'label' => 'Broken checkout', 'translation_domain' => null, 'route' => 'no_such_route'],
            ['id' => 'unchecked', 'label' => 'Checkout guide', 'translation_domain' => null, 'route' => 'test_attrs_page'],
        ],
        'filters'      => ['keys' => ['is', 'assignee', 'project', 'type']],
        'synonyms'     => ['builtin_dictionary' => true],
        'results_page' => ['enabled' => true, 'per_page' => 2, 'per_group' => 3],
        'recent'       => ['revalidate' => true],
    ];

    private ?TestKernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-search-kit-bundle');
    }

    public function testRegistryPagesNeedTheAccessCheckerAndNeverShowTheirKeywords(): void
    {
        $client = $this->client('alice');
        $data   = $this->json($client, '/search?q=avatar');
        $pages  = $this->items($data, 'pages');
        self::assertSame(['Profile settings'], array_column($pages, 'title'));
        self::assertSame('/settings/profile', $pages[0]['url'] ?? null);
        self::assertSame('Settings', $pages[0]['subtitle'] ?? null);
        self::assertStringNotContainsString('two-factor', (string) $client->getResponse()->getContent(), 'keywords are matched, never sent');

        // Allowed by the route rule but vetoed by the second checker: not listed, not counted.
        self::assertNull($this->group($this->json($client, '/search?q=invoice'), 'pages'));
        // The checker abstains on this route: deny by default.
        self::assertNull($this->group($this->json($client, '/search?q=guide'), 'pages'));
        // An unknown route never breaks the group.
        self::assertNull($this->group($this->json($client, '/search?q=broken'), 'pages'));
        // A keyword of a page the viewer may not open finds nothing at all.
        $denied = $this->json($client, '/search?q=secret-admin-keyword');
        self::assertSame(['echo'], array_column($denied['groups'], 'key'));

        $client->loginUser(new InMemoryUser('admin', 'x', ['ROLE_ADMIN']));
        self::assertSame(['Billing'], $this->titles($this->json($client, '/search?q=invoice'), 'pages'));
        $admin = $this->json($client, '/search?q=secret-admin-keyword');
        self::assertSame(['actions', 'pages', 'echo'], array_column($admin['groups'], 'key'));
        self::assertSame('/admin', $admin['groups'][1]['items'][0]['url']);
    }

    public function testKeywordsMatchAcrossLocalesAndSynonymsExpandTheQuery(): void
    {
        $client = $this->client('alice');
        // "contraseña" is a Spanish keyword: an English viewer finds the page too.
        self::assertSame(['Profile settings'], $this->titles($this->json($client, '/search?q=contrase%C3%B1a'), 'pages'));
        // "Kennwort" (de) is no keyword at all: the built-in dictionary expands it to "password".
        self::assertSame(['Profile settings'], $this->titles($this->json($client, '/search?q=Kennwort'), 'pages'));
        // A label of another locale is a keyword too.
        self::assertSame(['Billing'], $this->titles($this->json($client, '/search?q=facturaci%C3%B3n', 'admin'), 'pages'));
        // A keywords key with no message never matches its own key text.
        self::assertNull($this->group($this->json($client, '/search?q=language_keywords'), 'pages'));
    }

    public function testScopedTabsComeFromTheSourcesAndTheChecker(): void
    {
        $client = $this->client('alice');
        $data   = $this->json($client, '/search?q=members&scope=acme');
        self::assertSame(['/projects/acme/tabs/members'], $this->urls($data, 'pages'));
        self::assertSame(['/projects/acme/tabs/issues'], $this->urls($this->json($client, '/search?q=incidencias&scope=acme'), 'pages'));
        self::assertNull($this->group($this->json($client, '/search?q=members'), 'pages'), 'a scoped page needs a scope');

        // bob is not a member of acme: a hidden project and a project that does not exist answer the same.
        $client->loginUser(new InMemoryUser('bob', 'x', ['ROLE_USER']));
        $hidden  = $this->body($client, '/search?q=members&scope=acme');
        $missing = $this->body($client, '/search?q=members&scope=nope');
        self::assertSame(str_replace('nope', 'acme', $missing), $hidden);
    }

    public function testActionsComeFirstArePrefixedAndAccessChecked(): void
    {
        $client = $this->client('alice');
        $data   = $this->json($client, '/search?q=issue');
        self::assertSame('actions', $data['groups'][0]['key']);
        self::assertSame(['New issue'], array_column($data['groups'][0]['items'], 'title'));
        self::assertSame('n i', $data['groups'][0]['items'][0]['shortcut']);

        $only = $this->json($client, '/search?q=%3E');
        self::assertTrue($only['actionsOnly']);
        self::assertSame(['actions'], array_column($only['groups'], 'key'));
        self::assertSame(['New issue', 'Read the docs'], array_column($only['groups'][0]['items'], 'title'), 'admin-only and unrouted actions are denied');
        self::assertSame([], $this->json($client, '/search?q=%3Esecret-admin-keyword')['groups']);

        $context = $this->json($client, '/search?q=%3Eteam&from=' . rawurlencode('/projects/acme/page?x=1'));
        self::assertSame(['Project members'], array_column($context['groups'][0]['items'], 'title'));
        self::assertSame('acme', $context['groups'][0]['items'][0]['subtitle']);
        self::assertSame([], $this->json($client, '/search?q=%3Eteam&from=' . rawurlencode('//evil.test/projects/acme/page'))['groups']);
        $report = $this->json($client, '/search?q=%3Ereport&from=' . rawurlencode('/projects/acme/page'));
        self::assertSame(['acme'], array_column($report['groups'][0]['items'], 'subtitle'), 'alice may open that page: its context reaches the providers');

        $client->loginUser(new InMemoryUser('bob', 'x', ['ROLE_USER']));
        self::assertSame([], $this->json($client, '/search?q=%3Eteam&from=' . rawurlencode('/projects/acme/page'))['groups'], 'bob may not open acme');
        // Audit SP-L1: an allowed action must not carry the context of a page bob may not open (client-supplied `from`).
        self::assertSame([], $this->json($client, '/search?q=%3Ereport&from=' . rawurlencode('/projects/acme/page'))['groups']);
        self::assertSame(
            str_replace('nope', 'acme', $this->body($client, '/search?q=%3Enew&from=' . rawurlencode('/projects/nope/page'))),
            $this->body($client, '/search?q=%3Enew&from=' . rawurlencode('/projects/acme/page')),
            'a hidden page and a missing page give the same context',
        );
        $client->loginUser(new InMemoryUser('admin', 'x', ['ROLE_ADMIN']));
        self::assertContains('Admin console', array_column($this->json($client, '/search?q=%3E')['groups'][0]['items'], 'title'));
    }

    public function testFiltersRunOnlyTheProvidersThatApplyThemAndCannotBeUsedToProbe(): void
    {
        $client = $this->client('alice');
        $data   = $this->json($client, '/search?q=checkout+is%3Aopen');
        self::assertSame(['issues'], array_column($data['groups'], 'key'), 'providers without the filter are skipped');
        self::assertSame([['key' => 'is', 'value' => 'open', 'negated' => false, 'token' => 'is:open']], $data['filters']);
        self::assertSame(4, $data['groups'][0]['total'], 'open: acme #1, beta #3, #6, #7; the closed #2 and gamma #5 never counted');

        // A project that exists but is hidden, and a project that does not exist: byte-identical answers.
        self::assertSame(
            str_replace('nope', 'gamma', $this->body($client, '/search?q=checkout+project%3Anope')),
            $this->body($client, '/search?q=checkout+project%3Agamma'),
        );
        self::assertSame(
            str_replace('nobody', 'carol', $this->body($client, '/search?q=checkout+assignee%3Anobody')),
            $this->body($client, '/search?q=checkout+assignee%3Acarol'),
        );
        self::assertSame(
            str_replace('nothing', 'secret', $this->body($client, '/search?q=checkout+type%3Anothing')),
            $this->body($client, '/search?q=checkout+type%3Asecret'),
        );

        self::assertSame(['issues'], array_column($this->json($client, '/search?q=checkout+type%3Aissues')['groups'], 'key'));
        self::assertNotContains('issues', array_column($this->json($client, '/search?q=checkout+-type%3Aissues')['groups'], 'key'));
        self::assertSame(['Checkout timeout'], $this->titles($this->json($client, '/search?q=checkout+assignee%3Ame'), 'issues'));

        // Filters only: no text, only the providers that understand filters.
        $only = $this->json($client, '/search?q=is%3Aclosed');
        self::assertSame(['issues'], array_column($only['groups'], 'key'));
        self::assertSame(['Password reset mail'], $this->titles($only, 'issues'));
        // A too short text with a filter is refused like a too short text.
        self::assertSame([], $this->json($client, '/search?q=c+is%3Aopen')['groups']);
    }

    public function testTotalsNeverExceedTheVisibleRowsUnlessExact(): void
    {
        $client = $this->client('alice');
        $data   = $this->json($client, '/search?q=checkout');
        $issues = $this->group($data, 'issues');
        self::assertNotNull($issues);
        self::assertSame(4, $issues['total'], 'exact for alice: #1, #3, #6, #7 — never the hidden #5');
        self::assertCount(2, $issues['items']);
        self::assertSame(['Checkout timeout', 'Checkout button colour'], array_column($issues['items'], 'title'), 'same score: the newest first');

        $client->loginUser(new InMemoryUser('bob', 'x', ['ROLE_USER']));
        self::assertSame(3, $this->group($this->json($client, '/search?q=checkout'), 'issues')['total'] ?? null);
    }

    public function testFilterAllRowsDropsRowsTheCheckerDoesNotAllow(): void
    {
        $client = $this->client('alice', ['access' => ['filter_all_rows' => true]]);
        $data   = $this->json($client, '/search?q=shop');
        // /projects/{id} rows and /echo match no route: denied before they are counted.
        self::assertSame([], $data['groups']);
        self::assertSame(['issues'], array_column($this->json($client, '/search?q=checkout')['groups'], 'key'));
    }

    public function testRecentsAreRevalidatedForTheViewer(): void
    {
        $client = $this->client('alice');
        $token  = $this->config($client)['recent']['csrfToken'];
        self::assertIsString($token);
        $refs = [
            ['url' => '/issues/1', 'kind' => 'issues'],
            ['url' => '/issues/5', 'kind' => 'issues'],
            ['url' => '/issues/999', 'kind' => 'issues'],
            ['url' => '/settings/profile', 'kind' => 'pages'],
            ['url' => '/admin', 'kind' => 'pages'],
            ['url' => '/dashboard', 'kind' => 'pages'],
            ['url' => 'javascript:alert(1)', 'kind' => 'x'],
            ['url' => '/issues/1', 'kind' => 'issues'],
            'junk',
            ['url' => '/post-only', 'kind' => 'pages'],
        ];
        $items = $this->recent($client, $token, ['refs' => $refs]);
        self::assertSame(['/issues/1', '/settings/profile'], array_column($items, 'url'));
        self::assertSame(['Checkout timeout', 'Profile settings'], array_column($items, 'title'));
        self::assertSame('Acme Shop', $items[0]['subtitle']);

        // Malformed bodies answer exactly like references that are all hidden.
        self::assertSame([], $this->recent($client, $token, 'not json'));
        self::assertSame([], $this->recent($client, $token, ['refs' => 'nope']));
        self::assertSame([], $this->recent($client, $token, ''));
        self::assertSame([], $this->recent($client, $token, str_repeat('x', 40000)));

        $client->request(Request::METHOD_POST, '/search/recent', [], [], ['CONTENT_TYPE' => 'application/json'], '{"refs":[]}');
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame('{"error":"csrf"}', $client->getResponse()->getContent());
        $client->request(Request::METHOD_POST, '/search/recent', [], [], ['HTTP_X_CSRF_TOKEN' => 'forged'], '{"refs":[]}');
        self::assertSame(403, $client->getResponse()->getStatusCode());

        // The same references for bob: the acme issue and the profile page… only what bob may open.
        $client->loginUser(new InMemoryUser('bob', 'x', ['ROLE_USER']));
        $bobToken = $this->config($client)['recent']['csrfToken'];
        self::assertSame(['/settings/profile'], array_column($this->recent($client, (string) $bobToken, ['refs' => $refs]), 'url'));
    }

    public function testTheRecentsEndpointIsGuardedAndOffByDefault(): void
    {
        $client = $this->client(null);
        $client->request(Request::METHOD_POST, '/search/recent', [], [], [], '{}');
        self::assertSame(401, $client->getResponse()->getStatusCode());

        $client = $this->client('alice', ['role' => 'ROLE_ADMIN']);
        $client->request(Request::METHOD_POST, '/search/recent', [], [], [], '{}');
        self::assertSame(403, $client->getResponse()->getStatusCode());

        $this->kernel?->shutdown();
        $this->kernel = new TestKernel();
        $this->kernel->boot();
        $plain = $this->kernel->getContainer()->get('test.client');
        assert($plain instanceof KernelBrowser);
        $plain->loginUser(new InMemoryUser('alice', 'x', ['ROLE_USER']));
        $plain->request(Request::METHOD_POST, '/search/recent', [], [], [], '{}');
        self::assertSame(404, $plain->getResponse()->getStatusCode());
        $plain->request(Request::METHOD_GET, '/search/filters?key=is');
        self::assertSame(404, $plain->getResponse()->getStatusCode());
        $plain->request(Request::METHOD_GET, '/search/results?q=x');
        self::assertSame(404, $plain->getResponse()->getStatusCode());
    }

    public function testFilterValuesAreOnlyTheVisibleOnes(): void
    {
        $client = $this->client('alice');
        self::assertSame(['me', 'alice', 'bob'], array_column($this->values($client, 'assignee', ''), 'value'));
        self::assertSame([['value' => 'acme', 'label' => 'Acme Shop'], ['value' => 'beta', 'label' => 'Beta Shop']], $this->values($client, 'project', ''));
        self::assertSame(['bob'], array_column($this->values($client, 'assignee', 'b'), 'value'));
        // Unknown key, hidden value, failing provider: the same empty answer.
        self::assertSame([], $this->values($client, 'nope', ''));
        self::assertSame([], $this->values($client, 'project', 'gam'));
        self::assertSame([], $this->values($client, 'type', ''));
        self::assertSame([], $this->values($client, 'is', ''));

        $client->loginUser(new InMemoryUser('bob', 'x', ['ROLE_USER']));
        self::assertSame(['beta'], array_column($this->values($client, 'project', ''), 'value'));

        $guest = $this->client(null);
        $guest->request(Request::METHOD_GET, '/search/filters?key=project');
        self::assertSame(401, $guest->getResponse()->getStatusCode());
    }

    public function testTheResultsPageShowsOnlyVisibleRowsWithTabsChipsAndPages(): void
    {
        $client  = $this->client('alice');
        $crawler = $client->request(Request::METHOD_GET, '/search/results?q=checkout');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('secret', $html, 'gamma never appears');
        $tabs = $crawler->filter('.nowo-search-results__tab')->each(static fn ($tab): string => trim($tab->text()));
        self::assertSame(['All', 'Issues (3)', 'Echo (1)'], $tabs, 'counts are returned rows, not totals');
        self::assertCount(3, $crawler->filter('[data-group="top"] .nowo-search-results__row'), 'only scored rows (the echo row has no score)');
        self::assertStringContainsString('nowo-search-results.css', $html);

        $page1 = $client->request(Request::METHOD_GET, '/search/results?q=checkout&group=issues');
        self::assertCount(2, $page1->filter('.nowo-search-results__row'));
        self::assertSame('/search/results?q=checkout&group=issues&page=2', $page1->filter('a[rel="next"]')->attr('href'));
        $page2 = $client->request(Request::METHOD_GET, '/search/results?q=checkout&group=issues&page=2');
        self::assertCount(2, $page2->filter('.nowo-search-results__row'));
        self::assertSame('/search/results?q=checkout&group=issues', $page2->filter('a[rel="prev"]')->attr('href'));
        $page3 = $client->request(Request::METHOD_GET, '/search/results?q=checkout&group=issues&page=3');
        self::assertCount(0, $page3->filter('.nowo-search-results__row'));

        $echo = $client->request(Request::METHOD_GET, '/search/results?q=checkout&group=echo&scope=beta');
        self::assertSame(['checkout'], $echo->filter('.nowo-search-results__row-title')->each(static fn ($row): string => $row->text()));

        $chips = $client->request(Request::METHOD_GET, '/search/results?q=' . rawurlencode('checkout is:open -assignee:me'));
        self::assertSame(
            ['/search/results?q=-assignee:me%20checkout', '/search/results?q=is:open%20checkout'],
            $chips->filter('.nowo-search-results__chip')->each(static fn ($chip): ?string => $chip->attr('href')),
        );

        $none = $client->request(Request::METHOD_GET, '/search/results?q=zzzz+is%3Aopen');
        self::assertCount(1, $none->filter('.nowo-search-results__empty'));
        $empty = $client->request(Request::METHOD_GET, '/search/results');
        self::assertStringContainsString('Type something to search.', $empty->text());
        $actions = $client->request(Request::METHOD_GET, '/search/results?q=%3Eissue');
        self::assertSame(['All', 'Quick actions (1)'], $actions->filter('.nowo-search-results__tab')->each(static fn ($tab): string => trim($tab->text())));
    }

    public function testTheResultsPageIsGuardedAndRateLimited(): void
    {
        $guest = $this->client(null);
        $guest->request(Request::METHOD_GET, '/search/results?q=x');
        self::assertContains($guest->getResponse()->getStatusCode(), [401, 302]);

        $client = $this->client('alice', ['role' => 'ROLE_ADMIN']);
        $client->request(Request::METHOD_GET, '/search/results?q=x');
        self::assertSame(403, $client->getResponse()->getStatusCode());

        $client = $this->client('alice', ['rate_limit' => ['limit' => 1]]);
        $client->request(Request::METHOD_GET, '/search/results?q=checkout');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->request(Request::METHOD_GET, '/search/results?q=checkout');
        self::assertSame(429, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Too many searches', (string) $client->getResponse()->getContent());
        $client->request(Request::METHOD_GET, '/search/filters?key=project');
        self::assertSame(429, $client->getResponse()->getStatusCode());
        self::assertSame('{"error":"rate_limited"}', $client->getResponse()->getContent());
    }

    public function testTheBoxGetsTheFeatureKeysAndContextActionsOnlyWhenOn(): void
    {
        $client = $this->client('alice');
        $client->request(Request::METHOD_GET, '/projects/acme/page');
        $config = $this->configFromPage($client);
        self::assertSame('>', $config['actionPrefix']);
        self::assertSame(['is', 'assignee', 'project', 'type'], $config['filters']['keys']);
        self::assertSame('/search/filters', $config['filters']['valuesUrl']);
        self::assertSame(['url' => '/search/results', 'label' => 'See all results'], $config['results']);
        self::assertSame('/search/recent', $config['recent']['revalidateUrl']);
        self::assertSame('Actions only', $config['labels']['actionsOnly']);
        self::assertSame(['Host action', 'New issue', 'Read the docs', 'Report a bug here', 'Project members'], array_column($config['actions'], 'title'), 'host rows, then the context-aware, access-checked provider actions (no quick actions)');
        self::assertSame('n i', $config['actions'][1]['shortcut']);

        $client->loginUser(new InMemoryUser('bob', 'x', ['ROLE_USER']));
        $client->request(Request::METHOD_GET, '/projects/acme/page');
        self::assertSame(['Host action', 'New issue', 'Read the docs', 'Report a bug here'], array_column($this->configFromPage($client)['actions'], 'title'), 'the page bob is really on (server side), not a client claim');
        self::assertNotSame($config['recent']['storageKey'], $this->configFromPage($client)['recent']['storageKey'], 'one recents key per user');
    }

    public function testOneKeptAliveKernelServesEachViewerOnlyTheirOwnRows(): void
    {
        $client = $this->client('alice');
        for ($round = 0; $round < 2; ++$round) {
            $client->loginUser(new InMemoryUser('alice', 'x', ['ROLE_USER']));
            self::assertSame(4, $this->group($this->json($client, '/search?q=checkout'), 'issues')['total'] ?? null);
            $client->loginUser(new InMemoryUser('bob', 'x', ['ROLE_USER']));
            self::assertSame(3, $this->group($this->json($client, '/search?q=checkout'), 'issues')['total'] ?? null);
            self::assertSame([], $this->json($client, '/search?q=checkout+project%3Aacme')['groups']);
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function client(?string $user, array $extra = []): KernelBrowser
    {
        $this->kernel?->shutdown();
        $this->kernel = new TestKernel(array_replace_recursive(self::CONFIG, $extra), true, null, TestKernel::SCOPE_CONTEXT, true);
        $this->kernel->boot();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        if ($user !== null) {
            $client->loginUser(new InMemoryUser($user, 'x', [$user === 'admin' ? 'ROLE_ADMIN' : 'ROLE_USER']));
        }

        return $client;
    }

    private function body(KernelBrowser $client, string $url): string
    {
        $client->request(Request::METHOD_GET, $url);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());

        return (string) $client->getResponse()->getContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(KernelBrowser $client, string $url, ?string $as = null): array
    {
        if ($as !== null) {
            $client->loginUser(new InMemoryUser($as, 'x', ['ROLE_ADMIN']));
        }
        /** @var array<string, mixed> $data */
        $data = json_decode($this->body($client, $url), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    private function group(array $data, string $key): ?array
    {
        foreach (is_array($data['groups'] ?? null) ? $data['groups'] : [] as $group) {
            if (is_array($group) && ($group['key'] ?? null) === $key) {
                return $group;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<mixed>
     */
    private function titles(array $data, string $key): array
    {
        return array_column($this->items($data, $key), 'title');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<mixed>
     */
    private function urls(array $data, string $key): array
    {
        return array_column($this->items($data, $key), 'url');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function items(array $data, string $key): array
    {
        $items = $this->group($data, $key)['items'] ?? [];

        return is_array($items) ? array_values(array_filter($items, is_array(...))) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function config(KernelBrowser $client): array
    {
        $client->request(Request::METHOD_GET, '/page');

        return $this->configFromPage($client);
    }

    /**
     * @return array<string, mixed>
     */
    private function configFromPage(KernelBrowser $client): array
    {
        $json = $client->getCrawler()->filter('[data-nowo-search-config]')->attr('data-nowo-search-config');
        self::assertIsString($json);
        /** @var array<string, mixed> $config */
        $config = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $config;
    }

    /**
     * @param array<string, mixed>|string $body
     *
     * @return list<array<string, mixed>>
     */
    private function recent(KernelBrowser $client, string $token, array|string $body): array
    {
        $client->request(Request::METHOD_POST, '/search/recent', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR));
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        /** @var array{items: list<array<string, mixed>>} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(count($data['items']), array_unique(array_column($data['items'], 'url')));

        return $data['items'];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function values(KernelBrowser $client, string $key, string $prefix): array
    {
        $client->request(Request::METHOD_GET, '/search/filters?key=' . rawurlencode($key) . '&q=' . rawurlencode($prefix));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        /** @var array{values: list<array{value: string, label: string}>} $data */
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $data['values'];
    }
}
