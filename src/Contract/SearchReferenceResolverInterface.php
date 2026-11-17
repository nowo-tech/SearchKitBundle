<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Contract;

use Nowo\SearchKitBundle\Model\SearchItem;
use Nowo\SearchKitBundle\Model\SearchReference;

/**
 * Turns a recent reference back into a row for the viewer (1.8.0, `recent.revalidate`). With revalidation on, the
 * browser stores only references (URL + kind) and asks the bundle for the rows every time the recents are shown.
 *
 * Every service implementing it is tagged `nowo_search_kit.reference_resolver` (autoconfiguration). The bundle asks
 * the page registry and the menu pages first, then these services in tag priority order; the first row wins.
 *
 * Security contract: the bundle calls it only after the SearchAccessCheckerInterface allowed the URL. Still return a
 * row only for a record the viewer may open **now** (re-check, never cache), with the same URL as the reference;
 * return null for anything else (unknown, deleted, forbidden — the same null).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface SearchReferenceResolverInterface
{
    /** Service tag of the implementations (autoconfigured). */
    public const string REFERENCE_RESOLVER_TAG = 'nowo_search_kit.reference_resolver';

    /**
     * The row of a reference, for this viewer.
     *
     * @param SearchReference $reference URL, kind and matched route of the recent pick
     * @param string $userIdentifier The viewer
     * @param string|null $locale UI locale, or null
     *
     * @return SearchItem|null The row (title and subtitle in the UI locale), or null
     */
    public function resolve(SearchReference $reference, string $userIdentifier, ?string $locale): ?SearchItem;
}
