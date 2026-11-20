<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\EntitySearch;

use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Test\EntitySearchProviderTestCase;
use Nowo\SearchKitBundle\Test\QueryCounter;

/**
 * 1.11.2: a contract test that implements only the kit's required hooks, plus seedArchived() (needed while
 * hasArchivedState() keeps its default, true); every optional hook — seedBeyondTheBound(), changeSeededText(),
 * hiddenRowsFilteredInSql(), supportsRecents(), sensitiveTitles(), queryBudget(), prepareItemsBudget() — stays at its
 * default. The whole kit must pass on it with no test skipped (`--fail-on-skipped`). Not a test class itself (no
 * "Test" suffix); the viewer is created lazily so the kit methods also run on an instance PHPUnit did not set up.
 */
abstract class DefaultHooksNoteContract extends EntitySearchProviderTestCase
{
    private ?Viewer $viewer = null;

    protected function definition(): EntitySearchDefinition
    {
        return new DbalNoteDefinition(NoteDatabase::get()->connection, $this->viewer());
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

    protected function reference(int|string $id): SearchReference
    {
        return new SearchReference('/notes/' . $id, 'notes', 'note_show', ['id' => (string) $id]);
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
}
