<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Integration\EntitySearch;

use Nowo\SearchKitBundle\Tests\Support\EntitySearch\DefaultHooksNoteContract;
use PHPUnit\Framework\Attributes\Group;

/**
 * 1.11.2: the whole kit with every optional hook at its default; CI also runs it with `--fail-on-skipped`.
 */
#[Group('database')]
#[Group('kit-defaults')]
final class DefaultHooksNoteContractTest extends DefaultHooksNoteContract
{
}
