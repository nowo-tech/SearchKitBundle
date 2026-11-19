<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Integration\EntitySearch;

use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Test\EntitySearchProviderTestCase;
use Nowo\SearchKitBundle\Test\QueryCounter;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\NoteDatabase;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\PreparedNoteDefinition;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\Viewer;
use PHPUnit\Framework\Attributes\Group;

/**
 * 1.10.0: the contract kit against a definition that loads its titles in prepareItems() and has no archived state
 * (hasArchivedState() false, no seedArchived()). PrepareItemsTest also runs its kit methods with a broken definition.
 */
#[Group('database')]
final class PreparedNoteContractTest extends EntitySearchProviderTestCase
{
    /** Breaks the definition on purpose: one title query per shown row (N+1). */
    public bool $perRow = false;

    private ?Viewer $viewer = null;

    protected function definition(): EntitySearchDefinition
    {
        return new PreparedNoteDefinition(NoteDatabase::get()->connection, $this->viewer(), $this->perRow);
    }

    /**
     * The kit's default seedArchived(), for PrepareItemsTest.
     */
    public function defaultSeedArchived(): void
    {
        $this->seedArchived('tenant-a', 'x');
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

    protected function hasArchivedState(): bool
    {
        return false;
    }

    protected function actAs(int|string|null $tenant, string $userIdentifier): void
    {
        $this->viewer()->tenant         = $tenant;
        $this->viewer()->userIdentifier = $userIdentifier;
        $this->viewer()->feature        = true;
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
