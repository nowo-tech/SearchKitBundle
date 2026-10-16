<?php

declare(strict_types=1);

namespace App\Search;

use Symfony\Component\Security\Core\User\UserInterface;

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
        'scope'         => ['Search scope', 'SearchScopeResolverInterface and the scope chip'],
        'theming'       => ['Theming', 'The --nowo-search-* CSS tokens, dark mode and Twig blocks'],
        'shortcuts'     => ['Keyboard shortcuts', 'Ctrl/⌘ K, "/", arrows, Enter and Escape'],
    ];

    /** Username → display name. */
    public const array USERS = ['alice' => 'Alice Admin', 'bob' => 'Bob Builder'];

    /**
     * Projects the user may open.
     *
     * @return array<string, array{string, string, list<string>}>
     */
    public static function projectsFor(UserInterface $user): array
    {
        return array_filter(
            self::PROJECTS,
            static fn (array $project): bool => $project[2] === [] || \in_array($user->getUserIdentifier(), $project[2], true),
        );
    }
}
