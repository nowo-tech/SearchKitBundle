<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Contract;

/**
 * A provider that also receives queries shorter than `min_length` which match `min_length_exempt_patterns`.
 *
 * Every other provider never sees such a query: the minimum exists so that they do not scan for a fragment. When
 * `SearchQuery::$belowMinLength` is true, run only the exact lookup the pattern stands for, not a `LIKE`. The security
 * contract of {@see SearchProviderInterface} applies unchanged.
 *
 * It is a marker read with `instanceof` on the tagged service, so a decorator has to implement it as well.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
interface MinLengthExemptSearchProviderInterface extends SearchProviderInterface
{
}
