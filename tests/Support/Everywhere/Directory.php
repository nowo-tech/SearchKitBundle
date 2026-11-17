<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Everywhere;

use function in_array;

/**
 * Fixture data of the 1.8.0 "search everywhere" tests: projects, members and issues.
 *
 * alice: member of acme and beta; bob: member of beta only; admin: member of nothing (but ROLE_ADMIN).
 * The project "gamma" exists but has no member among the test users: it must never surface.
 */
final class Directory
{
    /** @var array<string, string> */
    public const array PROJECTS = ['acme' => 'Acme Shop', 'beta' => 'Beta Shop', 'gamma' => 'Gamma Secret'];

    /** @var array<string, list<string>> */
    public const array MEMBERS = ['acme' => ['alice'], 'beta' => ['alice', 'bob'], 'gamma' => ['carol']];

    /** @var list<array{id: int, project: string, title: string, open: bool, assignee: string, date: string}> */
    public const array ISSUES = [
        ['id' => 1, 'project' => 'acme', 'title' => 'Checkout timeout', 'open' => true, 'assignee' => 'alice', 'date' => '2026-11-16'],
        ['id' => 2, 'project' => 'acme', 'title' => 'Password reset mail', 'open' => false, 'assignee' => 'alice', 'date' => '2026-10-01'],
        ['id' => 3, 'project' => 'beta', 'title' => 'Checkout button colour', 'open' => true, 'assignee' => 'bob', 'date' => '2026-11-10'],
        ['id' => 4, 'project' => 'beta', 'title' => 'Login loop', 'open' => true, 'assignee' => 'alice', 'date' => '2026-09-01'],
        ['id' => 5, 'project' => 'gamma', 'title' => 'Checkout secret leak', 'open' => true, 'assignee' => 'carol', 'date' => '2026-11-17'],
        ['id' => 6, 'project' => 'beta', 'title' => 'Checkout copy', 'open' => true, 'assignee' => 'bob', 'date' => '2026-11-01'],
        ['id' => 7, 'project' => 'beta', 'title' => 'Checkout tax', 'open' => true, 'assignee' => 'bob', 'date' => '2026-11-02'],
    ];

    /**
     * Whether a user is a member of a project.
     */
    public static function isMember(string $user, string $project): bool
    {
        return in_array($user, self::MEMBERS[$project] ?? [], true);
    }

    /**
     * The projects a user belongs to.
     *
     * @return list<string>
     */
    public static function projectsOf(string $user): array
    {
        return array_values(array_filter(array_keys(self::PROJECTS), static fn (string $project): bool => self::isMember($user, $project)));
    }

    /**
     * The issue with that id, or null.
     *
     * @return array{id: int, project: string, title: string, open: bool, assignee: string, date: string}|null
     */
    public static function issue(int $id): ?array
    {
        foreach (self::ISSUES as $issue) {
            if ($issue['id'] === $id) {
                return $issue;
            }
        }

        return null;
    }
}
