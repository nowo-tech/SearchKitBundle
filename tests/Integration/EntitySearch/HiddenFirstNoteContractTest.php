<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Integration\EntitySearch;

use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Test\EntitySearchProviderTestCase;
use Nowo\SearchKitBundle\Test\QueryCounter;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\DbalNoteDefinition;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\NoteDatabase;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\Viewer;
use PHPUnit\Framework\Attributes\Group;

/**
 * 1.11.1: the whole provider contract kit against a definition that hides rows only in PHP, in a small window, and
 * orders every hidden row before every visible one — the unluckiest order a definition with equal timestamps and a
 * random id tiebreak can produce. No contract test may depend on the insertion order of the seeded rows.
 */
#[Group('database')]
final class HiddenFirstNoteContractTest extends EntitySearchProviderTestCase
{
    /** Above the 6 rows of the limit check, small enough that the window tests fill it. */
    private const int WINDOW = 8;

    private ?Viewer $viewer = null;

    protected function definition(): EntitySearchDefinition
    {
        return new DbalNoteDefinition(NoteDatabase::get()->connection, $this->viewer(), maxCandidates: self::WINDOW, hiddenFirst: true);
    }

    protected function queryCounter(): QueryCounter
    {
        return NoteDatabase::get()->counter;
    }

    protected function tenants(): array
    {
        return ['tenant-a', 'tenant-b'];
    }

    protected function seed(int|string $tenant, string $text): int
    {
        return NoteDatabase::get()->insert((string) $tenant, $text);
    }

    protected function seedHidden(int|string $tenant, string $text): int
    {
        return NoteDatabase::get()->insert((string) $tenant, $text, false, true);
    }

    protected function seedArchived(int|string $tenant, string $text): int
    {
        return NoteDatabase::get()->insert((string) $tenant, $text, true);
    }

    protected function actAs(int|string|null $tenant, string $userIdentifier): void
    {
        $viewer                 = $this->viewer();
        $viewer->tenant         = $tenant;
        $viewer->userIdentifier = $userIdentifier;
        $viewer->feature        = true;
    }

    protected function disableFeature(): void
    {
        $this->viewer()->feature = false;
    }

    private function viewer(): Viewer
    {
        return $this->viewer ??= new Viewer();
    }

    protected function reference(int|string $id): SearchReference
    {
        return new SearchReference('/notes/' . $id, 'notes', 'note_show', ['id' => (string) $id]);
    }
}
