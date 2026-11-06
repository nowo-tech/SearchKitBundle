<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Functional;

use Nowo\SearchKitBundle\Tests\Support\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Twig\Error\LoaderError;

use function assert;

/**
 * `toggle_icon_template`: a host partial inside the existing `nowo_search_toggle_icon` block, through a real kernel
 * (TwigBundle registered before this bundle), so the host needs no `templates/bundles/` override for one icon.
 */
final class ToggleIconTemplateTest extends TestCase
{
    private const string PARTIAL = '@SearchKitTest/toggle_icon.html.twig';

    private ?TestKernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-search-kit-bundle');
    }

    public function testThePartialReplacesTheThreeStateIconsInsideTheToggle(): void
    {
        $client  = $this->signedInClient(['toggle_icon_template' => self::PARTIAL]);
        $crawler = $client->request(Request::METHOD_GET, '/page');

        $toggle = $crawler->filter('button[data-part="toggle"]');
        self::assertCount(1, $toggle);
        self::assertCount(1, $toggle->filter('morph-icon.host-morph[data-search-morph]'));
        self::assertSame('nowo-search', $toggle->filter('morph-icon')->attr('data-root-id'), 'the partial gets the context of the block');
        self::assertCount(0, $toggle->filter('.nowo-search__toggle-icon'));
    }

    public function testOnlyTheToggleIconChanges(): void
    {
        $client  = $this->signedInClient(['toggle_icon_template' => self::PARTIAL]);
        $crawler = $client->request(Request::METHOD_GET, '/page');

        self::assertSame('nowo-search-field', $crawler->filter('[data-part="toggle"]')->attr('aria-controls'));
        self::assertSame('Search', $crawler->filter('[data-part="toggle"]')->attr('aria-label'));
        self::assertCount(1, $crawler->filter('input[data-part="input"]'));
        // Rows of the dropdown still draw from the icon templates, the search / close / loading ones included.
        self::assertCount(1, $crawler->filter('template[data-nowo-search-icon="search"]'));
        self::assertCount(1, $crawler->filter('template[data-nowo-search-icon="close"]'));
        self::assertCount(1, $crawler->filter('template[data-nowo-search-icon="loading"]'));
    }

    public function testThePathNeverReachesTheBrowser(): void
    {
        $client = $this->signedInClient(['toggle_icon_template' => self::PARTIAL]);
        $client->request(Request::METHOD_GET, '/page');
        $html = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('toggle_icon', $html);
        self::assertStringNotContainsString('toggleIcon', $html);
    }

    public function testGuestsGetNeitherTheBoxNorThePartial(): void
    {
        $this->kernel = new TestKernel(['toggle_icon_template' => self::PARTIAL]);
        $this->kernel->boot();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $crawler = $client->request(Request::METHOD_GET, '/page');

        self::assertCount(0, $crawler->filter('.nowo-search, morph-icon'));
    }

    public function testAMissingPartialFailsLoudly(): void
    {
        $client = $this->signedInClient(['toggle_icon_template' => '@SearchKitTest/does_not_exist.html.twig']);
        $client->catchExceptions(false);

        $this->expectException(LoaderError::class);
        $client->request(Request::METHOD_GET, '/page');
    }

    /**
     * The bytes of the default icons, indentation and newlines included: the option's conditional adds no whitespace
     * for a host that does not set it.
     */
    public function testWithoutTheOptionTheDefaultIconBytesAreUnchanged(): void
    {
        foreach ([[], ['toggle_icon_template' => null]] as $config) {
            $client = $this->signedInClient($config);
            $client->request(Request::METHOD_GET, '/page');
            $html = (string) $client->getResponse()->getContent();

            self::assertSame(1, preg_match('~title="[^"]*">\n(.*?)</button>~s', $html, $match));
            self::assertSame(1, preg_match(
                '~^ {52}<span class="nowo-search__toggle-icon nowo-search__toggle-icon--search"><svg.*?</svg></span>\n'
                . ' {28}<span class="nowo-search__toggle-icon nowo-search__toggle-icon--close"><svg.*?</svg></span>\n'
                . ' {28}<span class="nowo-search__toggle-icon nowo-search__toggle-icon--loading"><svg.*?</svg></span>\n'
                . ' {44}$~s',
                $match[1],
            ), 'got: ' . $match[1]);
            $this->kernel?->shutdown();
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function signedInClient(array $config): KernelBrowser
    {
        $this->kernel = new TestKernel($config);
        $this->kernel->boot();
        $client = $this->kernel->getContainer()->get('test.client');
        assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $client->loginUser(new InMemoryUser('bob', 'x', ['ROLE_USER']));

        return $client;
    }
}
