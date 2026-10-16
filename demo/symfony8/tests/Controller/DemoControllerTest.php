<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Smoke tests for the NowoSearchKitBundle demo.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class DemoControllerTest extends WebTestCase
{
    public function testRootRedirectsToEnglish(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseRedirects('/en');
    }

    public function testGuestsGetNoSearchBox(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Search Kit Bundle');
        self::assertSelectorExists('script[src*="/build/"]');
        self::assertSelectorNotExists('[data-nowo-search-config]');

        $client->request('GET', '/es');
        self::assertSelectorTextContains('html', 'buscador global');
    }

    public function testSignInShowsTheSearchBox(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/en/login');
        self::assertSelectorExists('input[name="_csrf_token"]');

        $form = $crawler->selectButton('Sign in')->form(['_username' => 'bob', '_password' => 'password']);
        $client->submit($form);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('nav', 'bob');
        self::assertSelectorExists('[data-nowo-search-config]');
        self::assertSelectorExists('input[name="q"][role="combobox"]');
        self::assertSelectorExists('template[data-nowo-search-icon="projects"]');
    }

    public function testSearchEndpointIsPermissionAware(): void
    {
        $client = static::createClient();
        $client->loginUser(new InMemoryUser('bob', 'password', ['ROLE_USER']));
        $client->request('GET', '/search?q=billing');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->searchGroups($client->getResponse()->getContent()));

        $client->request('GET', '/search?q=shop&scope=billing');
        self::assertSame([], $this->searchGroups($client->getResponse()->getContent()), 'a forbidden scope yields nothing, never 403');

        $client->loginUser(new InMemoryUser('alice', 'password', ['ROLE_ADMIN']));
        $client->request('GET', '/search?q=billing');
        $groups = $this->searchGroups($client->getResponse()->getContent());
        self::assertSame('projects', $groups[0]['key']);
        self::assertSame('Billing service', $groups[0]['items'][0]['title']);
        self::assertStringContainsString('<svg', (string) $groups[0]['items'][0]['icon']);

        $client->request('GET', '/search?q=alice');
        self::assertSame('users', $this->searchGroups($client->getResponse()->getContent())[0]['key']);
    }

    public function testProjectPagesAndScope(): void
    {
        $client = static::createClient();
        $client->loginUser(new InMemoryUser('bob', 'password', ['ROLE_USER']));
        $client->request('GET', '/en/projects/billing');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/en/projects/shop');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="nowo-search-scope"]', 'Online shop');
        $client->request('GET', '/en/docs/installation');
        self::assertResponseIsSuccessful();
    }

    public function testTwigTemplatesDoNotUseRawHtmlForms(): void
    {
        $templates = dirname(__DIR__, 2).'/templates';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($templates));
        foreach ($iterator as $file) {
            if (!$file->isFile() || 'twig' !== $file->getExtension()) {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression('/<form[\s>]/', $contents, $file->getPathname());
            self::assertDoesNotMatchRegularExpression('/<input[\s>]/', $contents, $file->getPathname());
        }
    }

    public function testViteEntrypointsAreBuilt(): void
    {
        self::bootKernel();
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        self::assertFileExists($projectDir.'/public/build/entrypoints.json');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchGroups(string|false $content): array
    {
        return json_decode((string) $content, true, 512, \JSON_THROW_ON_ERROR)['groups'];
    }
}
