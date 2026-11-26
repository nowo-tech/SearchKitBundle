<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Integration\EntitySearch;

use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Test\EntitySearchProviderTestCase;
use Nowo\SearchKitBundle\Test\QueryCounter;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\NoteDatabase;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\OrmNoteDefinition;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\Viewer;
use PHPUnit\Framework\Attributes\Group;

use function bin2hex;
use function random_bytes;

/**
 * The provider contract kit against an ORM definition (entities), on the test database.
 */
#[Group('database')]
final class OrmNoteContractTest extends EntitySearchProviderTestCase
{
    private Viewer $viewer;

    protected function setUp(): void
    {
        $this->viewer = new Viewer();
        NoteDatabase::get()->entityManager()->clear();
    }

    protected function definition(): EntitySearchDefinition
    {
        return new OrmNoteDefinition(NoteDatabase::get()->entityManager(), $this->viewer);
    }

    protected function queryCounter(): QueryCounter
    {
        return NoteDatabase::get()->counter;
    }

    protected function tenants(): array
    {
        return [41, 42];
    }

    protected function seed(int|string $tenant, string $text): int
    {
        return NoteDatabase::get()->insert((string) $tenant, 'Note', false, false, 'alice', $text);
    }

    protected function seedHidden(int|string $tenant, string $text): int
    {
        return NoteDatabase::get()->insert((string) $tenant, 'Note', false, true, 'alice', $text);
    }

    protected function seedArchived(int|string $tenant, string $text): int
    {
        return NoteDatabase::get()->insert((string) $tenant, 'Note', true, false, 'alice', $text);
    }

    protected function actAs(int|string|null $tenant, string $userIdentifier): void
    {
        $this->viewer->tenant         = $tenant;
        $this->viewer->userIdentifier = $userIdentifier;
        $this->viewer->feature        = true;
    }

    protected function disableFeature(): void
    {
        $this->viewer->feature = false;
    }

    protected function reference(int|string $id): SearchReference
    {
        return new SearchReference('/orm-notes/' . $id, 'orm_notes', 'orm_note_show', ['id' => (string) $id]);
    }

    protected function supportsRecents(): bool
    {
        return false;
    }

    protected function filterKey(): string
    {
        return 'owner';
    }

    protected function seedForFilter(int|string $tenant, string $text, bool $matching): int
    {
        $owner = $matching ? self::VIEWER_A : 'bob';

        return NoteDatabase::get()->insert((string) $tenant, 'Note', false, false, $owner, $text);
    }

    protected function matchingFilterValue(): string
    {
        return 'me';
    }

    /**
     * An owner who exists only in the other tenant, with notes containing the text.
     */
    protected function seedHiddenFilterValue(int|string $tenant, int|string $otherTenant, string $text): string
    {
        $owner = 'carol' . bin2hex(random_bytes(4));
        NoteDatabase::get()->insert((string) $otherTenant, 'Note', false, false, $owner, $text);
        NoteDatabase::get()->insert((string) $otherTenant, 'Note', false, false, $owner, $text);

        return $owner;
    }
}
