<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Functional;

use Nowo\SearchKitBundle\Tests\Support\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Translation\DataCollectorTranslator;

use function assert;
use function is_array;

use const JSON_THROW_ON_ERROR;

/**
 * The header partial: nothing for guests; for users the field (Form widget), the browser configuration, the scope
 * chip, quick actions, kind icon templates and the asset tags.
 */
final class SearchPartialTest extends TestCase
{
    private ?TestKernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-search-kit-bundle');
    }

    public function testGuestsGetNothing(): void
    {
        $crawler = $this->client()->request(Request::METHOD_GET, '/page');
        self::assertCount(0, $crawler->filter('.nowo-search'));
    }

    public function testSignedInUsersGetTheSearchBox(): void
    {
        $client = $this->client();
        $client->loginUser(new InMemoryUser('bob', 'x', ['ROLE_USER']));
        $crawler = $client->request(Request::METHOD_GET, '/page');

        $root = $crawler->filter('.nowo-search');
        self::assertCount(1, $root);
        self::assertSame('idle', $root->attr('data-state'));
        $input = $crawler->filter('input[data-part="input"]');
        self::assertSame('q', $input->attr('name'));
        self::assertSame('search', $input->attr('type'));
        self::assertSame('combobox', $input->attr('role'));
        self::assertSame('nowo-search-results', $input->attr('aria-controls'));
        self::assertSame('200', $input->attr('maxlength'));
        self::assertSame('Search…', $input->attr('placeholder'));
        self::assertCount(0, $crawler->filter('input[name*="_token"]'), 'no CSRF field: read-only GET');
        self::assertCount(0, $crawler->filter('[data-part="scope"]'));
        self::assertSame('Control+K Meta+K /', $crawler->filter('[data-part="toggle"]')->attr('aria-keyshortcuts'));
        self::assertSame('/bundles/nowosearchkit/css/nowo-search.css', $crawler->filter('link[rel="stylesheet"]')->attr('href'));
        self::assertSame('/bundles/nowosearchkit/js/nowo-search.js', $crawler->filter('script')->attr('src'));

        $kinds = $crawler->filter('template[data-nowo-search-icon]')->each(static fn (Crawler $node): string => (string) $node->attr('data-nowo-search-icon'));
        self::assertSame(['search', 'close', 'loading', 'pages', 'recent', 'action', 'item'], $kinds);
        self::assertStringContainsString('<circle cx="10" cy="10" r="7"', $crawler->filter('.nowo-search__toggle-icon--search')->html(), 'search comes from the UX icon set');

        $config = $this->config($crawler);
        self::assertSame('/search', $config['url']);
        self::assertSame(2, $config['minLength']);
        self::assertSame(150, $config['debounceMs']);
        self::assertTrue($config['shortcuts']);
        self::assertNull($config['scope']);
        self::assertSame('/bundles/nowosearchkit/css/nowo-search-popover.css', $config['popoverCss']);
        self::assertSame(['enabled' => true, 'max' => 8, 'storageKey' => 'nowo.search.recent.' . substr(hash_hmac('sha256', 'bob', 'search-kit-test'), 0, 16)], $config['recent'], 'per-user key, no identifier in the browser');
        self::assertSame([
            ['title' => 'Host action', 'subtitle' => 'from the host', 'url' => '/host', 'kind' => 'page'],
            ['title' => 'Configured page', 'subtitle' => '', 'url' => '/page?tab=x', 'kind' => 'action'],
            ['title' => 'Docs', 'subtitle' => '', 'url' => 'https://example.com/docs', 'kind' => 'page'],
        ], $config['actions']);
        self::assertSame('No results for “__QUERY__”', $config['labels']['empty']);
        self::assertSame('Type at least 2 characters to search.', $config['labels']['minChars']);
        self::assertSame('__COUNT__ results', $config['labels']['results']);
    }

    /**
     * The placeholder is translated by the template: the form theme must not look the translated text up again in
     * the field domain (it showed as a "missing message" in the profiler).
     */
    public function testTheTranslatedPlaceholderIsNotTranslatedAgain(): void
    {
        $client = $this->client();
        $client->loginUser(new InMemoryUser('bob', 'x', ['ROLE_USER']));
        $crawler = $client->request(Request::METHOD_GET, '/page');
        self::assertSame('Search…', $crawler->filter('input[data-part="input"]')->attr('placeholder'));

        $collector = $client->getContainer()->get(TestKernel::TRANSLATOR_COLLECTOR);
        assert($collector instanceof DataCollectorTranslator);
        $messages = $collector->getCollectedMessages();
        self::assertNotSame([], $messages);
        // Quick action labels come from the host configuration (`Configured page`, `Docs`): only the bundle domain.
        $missing = array_values(array_filter($messages, static fn (array $message): bool => $message['state'] === DataCollectorTranslator::MESSAGE_MISSING && $message['domain'] === 'NowoSearchKitBundle'));
        self::assertSame([], array_map(static fn (array $message): string => $message['domain'] . ': ' . $message['id'], $missing));
    }

    public function testScopeChipAndRoleFilteredActions(): void
    {
        $client = $this->client();
        $client->loginUser(new InMemoryUser('admin', 'x', ['ROLE_ADMIN']));
        $crawler = $client->request(Request::METHOD_GET, '/projects/beta/page');

        self::assertSame('In Beta Shop', trim($crawler->filter('.nowo-search__scope-label')->text()));
        $config = $this->config($crawler);
        self::assertSame(['id' => 'beta', 'label' => 'Beta Shop'], $config['scope']);
        self::assertContains('/admin', array_column($config['actions'], 'url'));

        $client->loginUser(new InMemoryUser('bob', 'x', ['ROLE_USER']));
        $crawler = $client->request(Request::METHOD_GET, '/projects/acme/page');
        self::assertCount(0, $crawler->filter('[data-part="scope"]'), 'the chip never names a project the user cannot open');
        $crawler = $client->request(Request::METHOD_GET, '/projects/unknown/page');
        self::assertNull($this->config($crawler)['scope']);
    }

    public function testAssetsShortcutsAndSvgInlineCanBeTurnedOff(): void
    {
        $client = $this->client([
            'assets'             => ['stylesheet' => false, 'script' => false],
            'keyboard_shortcuts' => false,
            'icon_set'           => 'svg_inline',
            'icons'              => ['projects' => 'tabler:bug'],
        ]);
        $client->loginUser(new InMemoryUser('alice', 'x', ['ROLE_USER']));
        $crawler = $client->request(Request::METHOD_GET, '/page');

        self::assertCount(0, $crawler->filter('link, script'));
        self::assertCount(0, $crawler->filter('[data-part="shortcut"]'));
        self::assertNull($crawler->filter('[data-part="toggle"]')->attr('aria-keyshortcuts'));
        self::assertNull($this->config($crawler)['popoverCss']);
        self::assertStringContainsString('class="nowo-search__svg"', $crawler->filter('template[data-nowo-search-icon="projects"]')->html());

        $client->request(Request::METHOD_GET, '/search?q=acme');
        $data = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($data));
        self::assertNull($data['groups'][0]['items'][0]['icon'], 'svg_inline: rows use the kind templates');
    }

    public function testWithoutUiKitTheWidgetKeepsItsOwnClasses(): void
    {
        $client = $this->client();
        $client->loginUser(new InMemoryUser('bob', 'x', ['ROLE_USER']));
        $crawler = $client->request(Request::METHOD_GET, '/page');

        self::assertSame('NowoSearchKitBundle', $crawler->filter('.nowo-search')->attr('data-nowo-ui-bundle'));
        self::assertSame('nowo-search__toggle', $crawler->filter('[data-part="toggle"]')->attr('class'));
        self::assertSame('nowo-search__input', $crawler->filter('[data-part="input"]')->attr('class'));
        self::assertSame('nowo-search__popover', $crawler->filter('[data-part="popover"]')->attr('class'));
        self::assertSame('nowo-search__footer', $crawler->filter('.nowo-search__footer')->attr('class'));
    }

    public function testUiKitThemeSlotsAddTheHostClassesOnly(): void
    {
        $client = $this->client([], [
            'css_framework' => 'bootstrap5',
            'theme'         => [
                'classes'    => ['btn_icon' => 'host-icon-btn', 'input' => 'host-input', 'card' => 'host-card', 'muted' => 'host-muted'],
                'attributes' => ['card' => ['data-host-card' => 'yes']],
                'bundles'    => ['NowoSearchKitBundle' => ['classes' => ['input' => 'search-only-input']]],
            ],
        ]);
        $client->loginUser(new InMemoryUser('bob', 'x', ['ROLE_USER']));
        $crawler = $client->request(Request::METHOD_GET, '/page');

        self::assertSame('nowo-search__toggle host-icon-btn', $crawler->filter('[data-part="toggle"]')->attr('class'));
        // Per-bundle exception wins; no UiKit framework default (form-control) on the compact field.
        self::assertSame('nowo-search__input search-only-input', $crawler->filter('[data-part="input"]')->attr('class'));
        self::assertSame('nowo-search__popover host-card', $crawler->filter('[data-part="popover"]')->attr('class'));
        self::assertSame('yes', $crawler->filter('[data-part="popover"]')->attr('data-host-card'));
        self::assertSame('nowo-search__footer host-muted', $crawler->filter('.nowo-search__footer')->attr('class'));
    }

    public function testHostAttributesOnTheRootAreMergedAndEscaped(): void
    {
        $client = $this->client();
        $client->loginUser(new InMemoryUser('alice', 'x', ['ROLE_USER']));
        $client->request(Request::METHOD_GET, '/page-attrs');
        $html    = (string) $client->getResponse()->getContent();
        $crawler = new Crawler($html);
        $root    = $crawler->filter('[data-nowo-search-config]');

        self::assertSame('nowo-search ms-2 header-search', $root->attr('class'));
        self::assertSame('nowo-search-kit', $root->attr('data-controller'));
        self::assertSame('a"b<c>', $root->attr('data-quote'));
        self::assertStringContainsString('data-quote="a&quot;b&lt;c&gt;"', $html, 'values are escaped');
        self::assertSame('', $root->attr('inert'), 'true renders a bare attribute');
        self::assertNull($root->attr('hidden'), 'false is skipped');
        self::assertNull($root->attr('data-null'), 'null is skipped');
        self::assertSame('nowo-search', $root->attr('id'), 'reserved attributes cannot be overridden');
        self::assertSame('idle', $root->attr('data-state'));
        self::assertNotSame('{}', $root->attr('data-nowo-search-config'));
        self::assertStringNotContainsString('bad name', $html);
        self::assertStringNotContainsString('<script>', $html, 'invalid attribute names are dropped');
    }

    public function testPopoverStylesheetCanBeReplacedOrTurnedOff(): void
    {
        $client = $this->client(['assets' => ['stylesheet' => false, 'popover_stylesheet' => '/build/search-popover.css']]);
        $client->loginUser(new InMemoryUser('alice', 'x', ['ROLE_USER']));
        $crawler = $client->request(Request::METHOD_GET, '/page');
        self::assertSame('/build/search-popover.css', $this->config($crawler)['popoverCss']);
        self::assertCount(0, $crawler->filter('link'));
        $this->kernel?->shutdown();

        $client = $this->client(['assets' => ['popover_stylesheet' => false]]);
        $client->loginUser(new InMemoryUser('alice', 'x', ['ROLE_USER']));
        $crawler = $client->request(Request::METHOD_GET, '/page');
        self::assertNull($this->config($crawler)['popoverCss']);
        self::assertCount(1, $crawler->filter('link[href$="nowo-search.css"]'));
    }

    public function testWithoutUxIconsTheBuiltInSvgIsUsed(): void
    {
        $this->kernel = new TestKernel([], false);
        $this->kernel->boot();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->loginUser(new InMemoryUser('alice', 'x', ['ROLE_USER']));
        $crawler = $client->request(Request::METHOD_GET, '/page');
        self::assertStringContainsString('class="nowo-search__svg"', $crawler->filter('.nowo-search__toggle-icon--search')->html());
    }

    /**
     * @param array<string, mixed> $config
     */
    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed>|null $uiKitConfig
     */
    private function client(array $config = [], ?array $uiKitConfig = null): KernelBrowser
    {
        $this->kernel = new TestKernel($config, true, $uiKitConfig);
        $this->kernel->boot();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(Crawler $crawler): array
    {
        $config = json_decode((string) $crawler->filter('.nowo-search')->attr('data-nowo-search-config'), true, 512, JSON_THROW_ON_ERROR);
        assert(is_array($config));

        /* @var array<string, mixed> $config */
        return $config;
    }
}
