<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Provider;

use function is_string;

/**
 * Stand-in for DashboardMenuBundle's MenuExtension: getMenuTree() / getHref() over plain item objects.
 */
final class FakeMenu
{
    /**
     * @param array<string, mixed> $trees Menu code → tree (a string simulates a broken source)
     */
    public function __construct(private readonly array $trees = [])
    {
    }

    public function getMenuTree(string $code): mixed
    {
        return $this->trees[$code] ?? [];
    }

    public function getHref(object $item, int $referenceType = 0): string
    {
        return property_exists($item, 'href') && is_string($item->href) ? $item->href : '';
    }

    /**
     * The tree the functional tests use.
     */
    public static function demo(): self
    {
        return new self([
            'main' => [
                ['item' => new FakeMenuItem('Dashboard', '/dashboard', 'tabler:home')],
                ['item' => new FakeMenuItem('Settings', '#'), 'children' => [
                    ['item' => new FakeMenuItem('Messenger monitor', '/admin/messenger')],
                    ['item' => new FakeMenuItem('Message templates', '/admin/templates', '')],
                ]],
            ],
            'admin' => [
                ['item' => new FakeMenuItem('Messenger monitor', '/admin/messenger')],
            ],
        ]);
    }
}
