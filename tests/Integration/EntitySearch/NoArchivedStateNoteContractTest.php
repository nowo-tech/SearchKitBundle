<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Integration\EntitySearch;

use Nowo\SearchKitBundle\Tests\Support\EntitySearch\DefaultHooksNoteContract;
use PHPUnit\Framework\Attributes\Group;

/**
 * 1.11.2: the whole kit with every optional hook at its default except hasArchivedState() false (records without an
 * archived state): the archived checks pass as not applicable, nothing is skipped (`--fail-on-skipped` in CI).
 */
#[Group('database')]
#[Group('kit-defaults')]
final class NoArchivedStateNoteContractTest extends DefaultHooksNoteContract
{
    protected function hasArchivedState(): bool
    {
        return false;
    }
}
