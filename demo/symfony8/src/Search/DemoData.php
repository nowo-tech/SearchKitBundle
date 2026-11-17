<?php

declare(strict_types=1);

namespace App\Search;


/**
 * In-memory records of the demo: projects (with per-user access), documentation pages and users.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class DemoData
{
    /** Project id → [name, description, members (empty = everyone)]. */
    public const array PROJECTS = [
        'shop'     => ['Online shop', 'Checkout, cart and payments', []],
        'mobile'   => ['Mobile app', 'iOS and Android clients', []],
        'billing'  => ['Billing service', 'Invoices, refunds and taxes', ['alice']],
        'platform' => ['Platform API', 'Public REST API and webhooks', ['alice']],
    ];

    /** Documentation slug → [title, summary]. */
    public const array DOCS = [
        'installation'  => ['Installation', 'Composer, bundles.php, routes and assets'],
        'configuration' => ['Configuration', 'Every nowo_search_kit option'],
        'providers'     => ['Search providers', 'SearchProviderInterface, priorities and permissions'],
        'scope'         => ['Search scope', 'SearchContextScopeResolverInterface and the scope chip'],
        'theming'       => ['Theming', 'The --nowo-search-* CSS tokens, dark mode and Twig blocks'],
        'shortcuts'     => ['Keyboard shortcuts', 'Ctrl/⌘ K, "/", arrows, Enter and Escape'],
    ];

    /** Issue id → [title, project id, open, last change] (spec 240 demo: filters, pages, recents). */
    public const array ISSUES = [
        1 => ['Checkout times out on slow networks', 'shop', true, '2026-11-15'],
        2 => ['Cart badge shows a stale count', 'shop', true, '2026-11-12'],
        3 => ['Checkout button unreadable in dark mode', 'mobile', true, '2026-11-10'],
        4 => ['Refund invoices miss the tax line', 'billing', true, '2026-11-16'],
        5 => ['Checkout webhook retries forever', 'platform', false, '2026-10-02'],
        6 => ['Password reset mail goes to spam', 'mobile', false, '2026-09-21'],
    ];

    /** Username → display name. */
    public const array USERS = ['alice' => 'Alice Admin', 'bob' => 'Bob Builder'];

    /**
     * Projects the user may open.
     *
     * @param string $userIdentifier The user's identifier
     *
     * @return array<string, array{string, string, list<string>}>
     */
    public static function projectsFor(string $userIdentifier): array
    {
        return array_filter(
            self::PROJECTS,
            static fn (array $project): bool => $project[2] === [] || \in_array($userIdentifier, $project[2], true),
        );
    }

    /**
     * Whether the user may open an issue (the user may open its project).
     *
     * @param string $userIdentifier The user's identifier
     * @param int $id Issue id
     *
     * @return bool True for an existing issue of a visible project
     */
    public static function canSeeIssue(string $userIdentifier, int $id): bool
    {
        return isset(self::ISSUES[$id]) && isset(self::projectsFor($userIdentifier)[self::ISSUES[$id][1]]);
    }
}
