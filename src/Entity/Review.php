<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Entity;

use c975L\SocialBundle\Repository\ReviewRepository;
use Doctrine\ORM\Mapping as ORM;

// A customer review imported from an external source (see ReviewsSourceInterface), never authored here: what a visitor reads must be what the source published, so the text and the rating have no setter of their own outside of a sync
#[ORM\Entity(repositoryClass: ReviewRepository::class)]
#[ORM\Table(name: 'site_review')]
#[ORM\UniqueConstraint(name: 'uniq_review_source_external', columns: ['source', 'external_id'])]
#[ORM\Index(name: 'idx_review_published', columns: ['published_at'])]
class Review implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // The name the source declares itself under (see ReviewsSourceInterface::getName()), so a sync only ever touches its own rows
    #[ORM\Column(length: 50)]
    private string $source;

    // The source's own identifier for this review, what makes a re-sync update rather than duplicate
    #[ORM\Column(length: 255)]
    private string $externalId;

    // Nullable because a platform can hand back a review with no display name; the card then prints its own "anonymous" label rather than the site inventing one at import time
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $authorName = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $authorAvatarUrl = null;

    #[ORM\Column(type: 'smallint')]
    private int $rating;

    // Nullable because a rating with no text is a review too - Google returns plenty of them
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\Column]
    private \DateTimeImmutable $publishedAt;

    // The owner's public answer, the only part of a review this site may write - pushed back to the source when it supports it
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $replyComment = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $repliedAt = null;

    // Deep link to the review on the source, required by Google's attribution rules and a proof of authenticity for the visitor
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $sourceUrl = null;

    // Whether the source ties the review to a real transaction or account; displayed as such, L111-7-2 requiring the site to say which reviews are verified
    #[ORM\Column(options: ['default' => true])]
    private bool $verified = true;

    public function __toString(): string
    {
        return sprintf('%s (%d/5)', $this->authorName ?? '', $this->rating);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function setExternalId(string $externalId): self
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getAuthorName(): ?string
    {
        return $this->authorName;
    }

    public function setAuthorName(?string $authorName): self
    {
        $this->authorName = $authorName;

        return $this;
    }

    public function getAuthorAvatarUrl(): ?string
    {
        return $this->authorAvatarUrl;
    }

    public function setAuthorAvatarUrl(?string $authorAvatarUrl): self
    {
        $this->authorAvatarUrl = $authorAvatarUrl;

        return $this;
    }

    public function getRating(): int
    {
        return $this->rating;
    }

    public function setRating(int $rating): self
    {
        $this->rating = $rating;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    public function getPublishedAt(): \DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(\DateTimeImmutable $publishedAt): self
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    public function getReplyComment(): ?string
    {
        return $this->replyComment;
    }

    public function setReplyComment(?string $replyComment): self
    {
        $this->replyComment = $replyComment;

        return $this;
    }

    public function getRepliedAt(): ?\DateTimeImmutable
    {
        return $this->repliedAt;
    }

    public function setRepliedAt(?\DateTimeImmutable $repliedAt): self
    {
        $this->repliedAt = $repliedAt;

        return $this;
    }

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function setSourceUrl(?string $sourceUrl): self
    {
        $this->sourceUrl = $sourceUrl;

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function setVerified(bool $verified): self
    {
        $this->verified = $verified;

        return $this;
    }
}
