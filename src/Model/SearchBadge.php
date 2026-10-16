<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Model;

/**
 * A small pill at the end of a row (e.g. a level or a status). The tone becomes the CSS modifier
 * `nowo-search__badge--{tone}` (letters, digits, "_" and "-" only).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final readonly class SearchBadge
{
    /**
     * @param string $label Text of the badge
     * @param string $tone Style hint: `neutral`, `info`, `success`, `warning`, `error`, `fatal` or your own
     */
    public function __construct(
        public string $label,
        public string $tone = 'neutral',
    ) {
    }

    /**
     * Badge as sent to the browser.
     *
     * @return array{label: string, tone: string} The badge with a CSS-safe tone
     */
    public function toArray(): array
    {
        return ['label' => $this->label, 'tone' => (string) preg_replace('/[^A-Za-z0-9_-]/', '', $this->tone)];
    }
}
