<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Contract;

/**
 * A menu item that carries search keywords (1.8.0): the Pages group matches them, never shows them.
 *
 * Optional: the Pages group also reads a `getSearchKeywords()` method by duck typing (DashboardMenuBundle need not
 * depend on SearchKit), and `menu_pages.keywords` maps a route to a keywords translation key without touching items.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface SearchKeywordsAwareInterface
{
    /**
     * Comma-separated keywords of the item, already translated (or null).
     *
     * @return string|null The keywords
     */
    public function getSearchKeywords(): ?string;
}
