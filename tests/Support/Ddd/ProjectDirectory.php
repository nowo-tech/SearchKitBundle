<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Ddd;

use function in_array;

/**
 * A DDD host's read model (plain PHP, no framework type): projects and their members, referenced by user identifier.
 */
final class ProjectDirectory
{
    /** @var array<string, array{name: string, members: list<string>}> */
    private const array PROJECTS = [
        'acme'  => ['name' => 'Acme Shop', 'members' => ['alice']],
        'beta'  => ['name' => 'Beta Shop', 'members' => ['alice', 'bob']],
        'gamma' => ['name' => 'Gamma Lab', 'members' => ['bob']],
    ];

    /**
     * @return array<string, string> Project id → name, only the projects the user is a member of
     */
    public function visibleTo(string $userIdentifier): array
    {
        $visible = [];
        foreach (self::PROJECTS as $id => $project) {
            if (in_array($userIdentifier, $project['members'], true)) {
                $visible[$id] = $project['name'];
            }
        }

        return $visible;
    }
}
