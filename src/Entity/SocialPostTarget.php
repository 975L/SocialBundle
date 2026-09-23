<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Entity;

use c975L\SocialBundle\Enum\SocialPostStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// One network's version of a post: its own text, cut to what that network accepts and editable until it goes out, and what became of it there
#[ORM\Entity]
#[ORM\Table(name: 'social_post_target')]
#[ORM\UniqueConstraint(name: 'social_post_target_network', columns: ['post_id', 'network'])]
class SocialPostTarget
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: SocialPostStatus::class)]
    private SocialPostStatus $status = SocialPostStatus::Draft;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    // What the network calls the post (Bluesky's "at://" uri), kept to find it again there
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalId = null;

    // The network's own words for its refusal, shown on the screen where the post is published again
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: SocialPost::class, inversedBy: 'targets')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private SocialPost $post,
        #[ORM\Column(length: 32)]
        private string $network,
        #[ORM\Column(type: Types::TEXT)]
        private string $text,
    ) {
        $this->post->addTarget($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPost(): SocialPost
    {
        return $this->post;
    }

    public function getNetwork(): string
    {
        return $this->network;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(?string $text): self
    {
        $this->text = trim((string) $text);

        return $this;
    }

    public function getStatus(): SocialPostStatus
    {
        return $this->status;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    // Whether "Publish" still has something to do here - a published target is never sent twice
    public function isPending(): bool
    {
        return SocialPostStatus::Published !== $this->status;
    }

    public function markPublished(string $externalId): void
    {
        $this->status = SocialPostStatus::Published;
        $this->publishedAt = new \DateTimeImmutable();
        $this->externalId = $externalId;
        $this->error = null;
    }

    public function markFailed(string $error): void
    {
        $this->status = SocialPostStatus::Failed;
        $this->error = $error;
    }
}
