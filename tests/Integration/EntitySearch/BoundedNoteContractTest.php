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
 * 1.11.0: the whole provider contract kit against a definition that bounds its own candidates (at most three owners
 * whose notes match the text, like a bundle that reads at most N boards), including the bounded-candidates check.
 */
#[Group('database')]
final class BoundedNoteContractTest extends EntitySearchProviderTestCase
{
    private const int BOUND = 3;

    /** False: the definition hits its bound without marking the candidates partial (the kit must fail it). */
    public bool $marksPartial = true;

    private ?Viewer $viewer = null;

    protected function definition(): EntitySearchDefinition
    {
        return new DbalNoteDefinition(NoteDatabase::get()->connection, $this->viewer(), self::BOUND, $this->marksPartial);
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

    protected function seedBeyondTheBound(int|string $tenant, string $text): bool
    {
        for ($owner = 0; $owner <= self::BOUND; ++$owner) {
            NoteDatabase::get()->insert((string) $tenant, $text . 'b' . $owner, false, false, 'bound-' . $owner);
        }

        return true;
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
