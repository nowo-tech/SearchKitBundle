<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Everywhere;

use Nowo\SearchKitBundle\Contract\SearchFilterValuesProviderInterface;
use Nowo\SearchKitBundle\Model\SearchFilterValue;
use RuntimeException;

/**
 * Autocomplete of `assignee:` (members of the viewer's projects only) and `project:`; a key "boom" throws.
 */
final class AssigneeValues implements SearchFilterValuesProviderInterface
{
    public function supportsKey(string $key): bool
    {
        if ($key === 'type') {
            throw new RuntimeException('broken values provider');
        }

        return $key === 'assignee' || $key === 'project';
    }

    public function values(string $key, string $prefix, string $userIdentifier, ?string $scope, int $limit): array
    {
        $values = $key === 'assignee' ? [new SearchFilterValue('me', 'me')] : [];
        foreach (Directory::projectsOf($userIdentifier) as $project) {
            if ($key === 'project') {
                $values[] = new SearchFilterValue($project, Directory::PROJECTS[$project]);

                continue;
            }
            foreach (Directory::MEMBERS[$project] as $member) {
                $values[] = new SearchFilterValue($member);
            }
        }

        return array_values(array_filter($values, static fn (SearchFilterValue $value): bool => $prefix === '' || str_starts_with($value->value, $prefix)));
    }
}
