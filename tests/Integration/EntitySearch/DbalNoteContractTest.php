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

use function bin2hex;
use function random_bytes;

/**
 * The provider contract kit against a DBAL definition, on the test database (SQLite, or MySQL / MariaDB / PostgreSQL
 * through SEARCH_KIT_TEST_DATABASE_URL).
 */
#[Group('database')]
final class DbalNoteContractTest extends EntitySearchProviderTestCase
{
    private Viewer $viewer;

    protected function setUp(): void
    {
        $this->viewer = new Viewer();
    }

    protected function definition(): EntitySearchDefinition
    {
        return new DbalNoteDefinition(NoteDatabase::get()->connection, $this->viewer);
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
        $this->viewer->tenant         = $tenant;
        $this->viewer->userIdentifier = $userIdentifier;
        $this->viewer->feature        = true;
    }

    protected function disableFeature(): void
    {
        $this->viewer->feature = false;
    }

    protected function queryBudget(): int
    {
        return 1;
    }

    protected function reference(int|string $id): SearchReference
    {
        return new SearchReference('/notes/' . $id, 'notes', 'note_show', ['id' => (string) $id]);
    }

    protected function filterKey(): string
    {
        return 'owner';
    }

    protected function seedForFilter(int|string $tenant, string $text, bool $matching): int
    {
        $owner = $matching ? self::VIEWER_A : 'bob';

        return NoteDatabase::get()->insert((string) $tenant, $text, false, false, $owner);
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
        NoteDatabase::get()->insert((string) $otherTenant, $text, false, false, $owner);
        NoteDatabase::get()->insert((string) $otherTenant, $text, false, false, $owner);

        return $owner;
    }
}
