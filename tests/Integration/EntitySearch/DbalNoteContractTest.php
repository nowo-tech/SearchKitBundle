<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Integration\EntitySearch;

use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Test\EntitySearchProviderTestCase;
use Nowo\SearchKitBundle\Test\QueryCounter;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\DbalNoteDefinition;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\NoteDatabase;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\Viewer;
use PHPUnit\Framework\Attributes\Group;

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

    protected function seed(int|string $tenant, string $text): void
    {
        NoteDatabase::get()->insert((string) $tenant, $text);
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
}
