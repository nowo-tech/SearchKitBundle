<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Provider\Entity;

/**
 * What a definition says about the candidates of one search (1.11.0): EntitySearchProvider creates a new context for
 * every search page and every recent it resolves, passes it to EntitySearchDefinition::candidateQuery() and
 * filterCandidates(), reads it once the candidates are checked, and drops it when the call returns. It is never a
 * service, never shared between calls and never kept by SearchKit, so a flag set for one viewer's search cannot reach
 * another search or another request in FrankenPHP worker mode with kernel reset off.
 *
 * markPartial(): the definition bounded its own candidates for this search (e.g. it scanned at most N boards of the
 * tenant and more exist), so the rows SearchKit sees may not be all the matches. The group's total is then reported
 * as inexact (`SearchGroup::$exactTotal` false; SearchKit caps it to the rows it lists, "N+"). It never hides a row
 * nor changes which rows are shown. Once marked, a context stays partial.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class SearchCandidateContext
{
    private bool $partial = false;

    /**
     * Marks the candidates of this search as bounded by the definition: the total is not exact.
     */
    public function markPartial(): void
    {
        // @igor-ignore - per-search object, created for one search page or one recent and never reused (not a service)
        $this->partial = true;
    }

    /**
     * Whether the definition marked the candidates of this search as bounded.
     */
    public function isPartial(): bool
    {
        return $this->partial;
    }
}
