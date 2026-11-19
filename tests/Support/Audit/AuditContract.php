<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\Audit;

use Nowo\SearchKitBundle\Model\SearchReference;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Test\EntitySearchProviderTestCase;
use Nowo\SearchKitBundle\Test\QueryCounter;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\NoteDatabase;
use Nowo\SearchKitBundle\Tests\Support\EntitySearch\Viewer;

/**
 * Audit VF: the contract kit wired to an AuditNoteDefinition whose switches a regression test sets (break the tenant
 * condition or the per-row check, mark it sensitive, shrink the window). Not a test class (no "Test" suffix): the
 * regression test calls its kit methods directly.
 */
final class AuditContract extends EntitySearchProviderTestCase
{
    public bool $tenantInSql = true;

    public bool $checkHidden = true;

    public int $candidates = 100;

    /** The definition's sensitive(). */
    public bool $sensitiveDefinition = false;

    /** What the test declares (sensitiveTitles()). */
    public bool $sensitive = false;

    private ?Viewer $viewer = null;

    protected function definition(): EntitySearchDefinition
    {
        return new AuditNoteDefinition(NoteDatabase::get()->connection, $this->viewer(), $this->tenantInSql, $this->checkHidden, $this->candidates, false, $this->sensitiveDefinition);
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

    protected function supportsRecents(): bool
    {
        return false;
    }

    protected function sensitiveTitles(): bool
    {
        return $this->sensitive;
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
}
