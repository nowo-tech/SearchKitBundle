<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Contract;

/**
 * A provider that understands typed filters (1.8.0): `SearchQuery::$filters` (`is:open`, `assignee:me`, `project:x` …).
 *
 * **Deny by default:** when a query carries a filter key, only the providers that list it in supportedFilters() run;
 * every other provider is skipped (it would ignore the filter and return rows the viewer did not ask for). The
 * built-in key `type` is handled by the engine (it keeps the groups whose key matches) and need not be listed.
 *
 * Anti-probing contract: resolve each value for the viewer (`me`, a project the viewer may open …) and treat an unknown
 * or unauthorised value exactly like a value with no match — no rows, same response, no exception, no different count
 * — and do comparable work in both cases where practical.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface FilterableSearchProviderInterface extends SearchProviderInterface
{
    /**
     * The filter keys this provider applies.
     *
     * @return list<string> Keys (`SearchFilter::KEY_PATTERN`), e.g. `['is', 'assignee', 'project']`
     */
    public function supportedFilters(): array;
}
