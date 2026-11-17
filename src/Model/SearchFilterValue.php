<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Model;

/**
 * One autocomplete suggestion for a filter value (SearchFilterValuesProviderInterface, 1.8.0): only values the viewer
 * may see.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchFilterValue
{
    /**
     * @param string $value The value typed after `key:` (quoted by the browser when it has spaces)
     * @param string $label Text of the suggestion (defaults to the value)
     */
    public function __construct(
        public string $value,
        public string $label = '',
    ) {
    }

    /**
     * Suggestion as sent to the browser.
     *
     * @return array{value: string, label: string} The suggestion
     */
    public function toArray(): array
    {
        return ['value' => $this->value, 'label' => $this->label !== '' ? $this->label : $this->value];
    }
}
