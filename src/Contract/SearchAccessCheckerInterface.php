<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Contract;

use Nowo\SearchKitBundle\Model\SearchAccessRequest;

/**
 * Decides whether a viewer may open a target the bundle is about to list (1.8.0): a registry page, a page source
 * entry, a palette action, a recent reference — and, with `access.filter_all_rows`, every provider row.
 *
 * Implement it with the host's route access rule (route + parameters, the permission catalogue, the access map …).
 * Every service implementing it is tagged `nowo_search_kit.access_checker` (autoconfiguration).
 *
 * **Deny by default.** A target is listed only when at least one checker returns true and none returns false:
 * - true: allowed by this checker;
 * - false: denied (a veto: no other checker can allow it);
 * - null: no decision (another checker may decide).
 * No checker at all, or only null answers, means **denied**: the registry, the provider actions and the revalidated
 * recents then list nothing. A checker that throws counts as a denial (logged, the search still answers).
 *
 * **Decide for `$request->userIdentifier`, never for the session token** (`Security::getUser()`, the token storage):
 * a search by identifier (SearchEngineInterface::searchFor(), CLI, API) runs for another user than the HTTP session,
 * and a checker reading the session would list that session user's pages to the searched user.
 *
 * Decide on the request only (no side effects, no per-request caching in a property: FrankenPHP worker mode), and do
 * the same amount of work whether the target exists or not where practical.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface SearchAccessCheckerInterface
{
    /** Service tag of the implementations (autoconfigured). */
    public const string ACCESS_CHECKER_TAG = 'nowo_search_kit.access_checker';

    /**
     * Whether the viewer may open the target.
     *
     * @param SearchAccessRequest $request The viewer, the target (URL, route, parameters) and what is being listed
     *
     * @return bool|null True to allow, false to deny, null for no decision (denied unless another checker allows)
     */
    public function decide(SearchAccessRequest $request): ?bool;
}
