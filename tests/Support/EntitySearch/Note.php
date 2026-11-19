<?php

declare(strict_types=1);

namespace Nowo\SearchKitBundle\Tests\Support\EntitySearch;

use Doctrine\ORM\Mapping as ORM;

/**
 * ORM entity of the test notes table (NoteDatabase::TABLE).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sk_note')]
class Note
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null; // @phpstan-ignore property.unusedType (set by the ORM)

    public function __construct(
        #[ORM\Column(type: 'string', length: 64)]
        private string $tenant,
        #[ORM\Column(type: 'string', length: 255)]
        private string $title,
        #[ORM\Column(type: 'string', length: 64)]
        private string $owner = 'alice',
        #[ORM\Column(type: 'boolean')]
        private bool $archived = false,
        #[ORM\Column(type: 'boolean')]
        private bool $hidden = false,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $body = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): string
    {
        return $this->tenant;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getOwner(): string
    {
        return $this->owner;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }
}
