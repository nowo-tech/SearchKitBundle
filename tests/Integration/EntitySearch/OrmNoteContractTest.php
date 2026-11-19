<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Integration\EntitySearch;

use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Test\EntitySearchProviderTestCase;
use Nowo\SearchKitBundle\Test\QueryCounter;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\NoteDatabase;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\OrmNoteDefinition;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\Viewer;
use PHPUnit\Framework\Attributes\Group;

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

    protected function seed(int|string $tenant, string $text): void
    {
        NoteDatabase::get()->insert((string) $tenant, 'Note', false, false, 'alice', $text);
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
}
