<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Provider;

/** Menu item stand-in (getLabel / getIcon like DashboardMenuBundle's MenuItem). */
final class FakeMenuItem
{
    public function __construct(
        private readonly string $label,
        public readonly string $href,
        private readonly ?string $icon = null,
    ) {
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }
}
