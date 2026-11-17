<?php

declare(strict_types=1);

namespace App\Search;

use Nowo\SearchKitBundle\Contract\SearchAccessCheckerInterface;
use Nowo\SearchKitBundle\Model\SearchAccessRequest;

/**
 * The demo's route access rule (spec 240): the search lists a page, an action or a recent pick only when this says
 * yes. Any route it does not know gets no decision — and the bundle then denies it (deny by default).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class DemoAccessChecker implements SearchAccessCheckerInterface
{
    public function decide(SearchAccessRequest $request): ?bool
    {
        $admin = $request->userIdentifier === 'alice';
        $id    = (string) ($request->routeParameters['id'] ?? '');

        return match ($request->route) {
            'homepage', 'demo_doc'                  => true,
            'demo_settings'                         => ($request->routeParameters['section'] ?? null) !== 'billing' || $admin,
            'demo_users'                            => $admin,
            'demo_project', 'demo_project_tab'      => isset(DemoData::projectsFor($request->userIdentifier)[$id]),
            'demo_issue'                            => DemoData::canSeeIssue($request->userIdentifier, (int) $id),
            default                                 => null,
        };
    }
}
