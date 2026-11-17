<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Everywhere;

use Nowo\SearchKitBundle\Contract\SearchAccessCheckerInterface;
use Nowo\SearchKitBundle\Model\SearchAccessRequest;

use function is_string;

/**
 * The host's route access rule of the tests. Abstains (null) on every route it does not know: the bundle then denies.
 */
final class RouteAccessChecker implements SearchAccessCheckerInterface
{
    public function decide(SearchAccessRequest $request): ?bool
    {
        $admin   = $request->userIdentifier === 'admin';
        $project = $request->routeParameters['id'] ?? null;

        return match ($request->route) {
            'test_page', 'test_settings', 'test_new_issue' => $request->attribute === 'ROLE_ADMIN' ? $admin : true,
            'test_admin'                                   => $admin,
            'test_project_tab', 'test_project_page'        => is_string($project) && Directory::isMember($request->userIdentifier, $project),
            'test_issue'                                   => $this->canSeeIssue($request),
            null                                           => str_starts_with($request->url, 'https://example.com/') ? true : null,
            default                                        => null,
        };
    }

    private function canSeeIssue(SearchAccessRequest $request): bool
    {
        $issue = Directory::issue((int) ($request->routeParameters['id'] ?? 0));

        return $issue !== null && Directory::isMember($request->userIdentifier, $issue['project']);
    }
}
