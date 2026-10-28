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

    // The first comment the post is answered with, where the link goes on a network showing a post with a link to fewer people (see FirstCommentPublisherInterface)
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    // What the network calls the comment once it is there - never sent twice
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $commentId = null;

    // The network's refusal of the comment, the post itself staying published: only the comment is sent again
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentError = null;

    // The text the post is shared again with on the connected member's own profile, when it goes out as a Page (see ProfileResharePublisherInterface)
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reshare = null;

    // What the network calls the reshare once it is there - never sent twice
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reshareId = null;

    // The network's refusal of the reshare, the post itself staying published: only the reshare is sent again
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reshareError = null;

    // Whether the post's picture goes out as a story too, right after the post (see StoryPublisherInterface)
    #[ORM\Column(options: ['default' => false])]
    private bool $story = false;

    // What the network calls the story once it is there - never sent twice
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $storyId = null;

    // The network's refusal of the story, the post itself staying published: only the story is sent again
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $storyError = null;

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

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): self
    {
        $comment = trim((string) $comment);
        $this->comment = '' === $comment ? null : $comment;

        return $this;
    }

    public function getCommentId(): ?string
    {
        return $this->commentId;
    }

    public function getCommentError(): ?string
    {
        return $this->commentError;
    }

    // Published, with a comment still to send - the post never sent again for it
    public function isCommentPending(): bool
    {
        return SocialPostStatus::Published === $this->status && null !== $this->comment && null === $this->commentId;
    }

    public function markCommented(string $commentId): void
    {
        $this->commentId = $commentId;
        $this->commentError = null;
    }

    public function markCommentFailed(string $error): void
    {
        $this->commentError = $error;
    }

    public function getReshare(): ?string
    {
        return $this->reshare;
    }

    public function setReshare(?string $reshare): self
    {
        $reshare = trim((string) $reshare);
        $this->reshare = '' === $reshare ? null : $reshare;

        return $this;
    }

    public function getReshareId(): ?string
    {
        return $this->reshareId;
    }

    public function getReshareError(): ?string
    {
        return $this->reshareError;
    }

    // Published, with a reshare still to send - the post never sent again for it
    public function isResharePending(): bool
    {
        return SocialPostStatus::Published === $this->status && null !== $this->reshare && null === $this->reshareId;
    }

    public function markReshared(string $reshareId): void
    {
        $this->reshareId = $reshareId;
        $this->reshareError = null;
    }

    public function markReshareFailed(string $error): void
    {
        $this->reshareError = $error;
    }

    public function isStory(): bool
    {
        return $this->story;
    }

    public function setStory(bool $story): self
    {
        $this->story = $story;

        return $this;
    }

    public function getStoryId(): ?string
    {
        return $this->storyId;
    }

    public function getStoryError(): ?string
    {
        return $this->storyError;
    }

    // Published, with a story still to send - the post never sent again for it
    public function isStoryPending(): bool
    {
        return SocialPostStatus::Published === $this->status && $this->story && null === $this->storyId;
    }

    public function markStoried(string $storyId): void
    {
        $this->storyId = $storyId;
        $this->storyError = null;
    }

    public function markStoryFailed(string $error): void
    {
        $this->storyError = $error;
    }

    // Published, with its first comment, its reshare or its story still to send, what "Send again" then does alone
    public function isFollowUpPending(): bool
    {
        return $this->isCommentPending() || $this->isResharePending() || $this->isStoryPending();
    }

    // Whether "Publish" still has something to do here - a published target is never sent twice
    public function isPending(): bool
    {
        return SocialPostStatus::Published !== $this->status;
    }

    // A failed target is approved too: the planned run then tries it again, its refusal kept until it goes out
    public function approve(): void
    {
        if ($this->isPending()) {
            $this->status = SocialPostStatus::Approved;
        }
    }

    public function unapprove(): void
    {
        if (SocialPostStatus::Approved === $this->status) {
            $this->status = SocialPostStatus::Draft;
        }
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
