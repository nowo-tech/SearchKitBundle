<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Model;

/**
 * One alternative of the query text: the text itself, or a synonym of it in one locale
 * (SearchSynonymProviderInterface, 1.8.0). See SearchQuery::terms().
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchTerm
{
    /**
     * @param string $text The alternative text (as typed or as the dictionary spells it)
     * @param string|null $locale Locale of the alternative (null for the typed text, whatever its language)
     * @param bool $synonym False for the typed text, true for an expansion
     */
    public function __construct(
        public string $text,
        public ?string $locale = null,
        public bool $synonym = false,
    ) {
    }
}
