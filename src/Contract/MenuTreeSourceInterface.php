<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Contract;

/**
 * Menu source of the built-in Pages group (`menu_pages.service`).
 *
 * Implement it on your own navigation model (REQ-ARCH-001 rule 5). A service without it still works when it exposes
 * the same two methods (DashboardMenuBundle's Twig extension does), read by duck typing as before 1.7.0.
 *
 * Security contract: the tree holds only the entries the current user may open; the Pages group lists what it gets.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface MenuTreeSourceInterface
{
    /**
     * The visible tree of one menu.
     *
     * @param string $code Menu code
     *
     * @return array<mixed> Nodes `{item: object, children?: list<node>}`; items need `getLabel(): string` and may have
     *                      `getIcon(): ?string`
     */
    public function getMenuTree(string $code): array;

    /**
     * The URL of one menu item.
     *
     * @param object $item An item from getMenuTree()
     * @param int $referenceType A `UrlGeneratorInterface::*` reference type (the Pages group asks for ABSOLUTE_PATH)
     *
     * @return string The URL
     */
    public function getHref(object $item, int $referenceType): string;
}
