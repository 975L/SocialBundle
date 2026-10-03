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
use c975L\SocialBundle\Repository\SocialPostRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// One content posted, whatever the networks: what the scheduled run prepared, with one target per network it goes to (see SocialPostTarget). Its rows are what keeps a content from being offered again - whatever its targets became, a post prepared is a content taken. Title, url and image url are kept as they were when prepared: a post made from a page's Open Graph tags has no source to read them from again, and the list names each post by them
#[ORM\Entity(repositoryClass: SocialPostRepository::class)]
#[ORM\Table(name: 'social_post')]
#[ORM\Index(name: 'social_post_source', columns: ['source_type', 'source_id'])]
#[ORM\Index(name: 'social_post_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'social_post_planned_at', columns: ['planned_at'])]
class SocialPost implements \Stringable
{
    // The source type of a post prepared from a page's url rather than handed by a source
    public const string SOURCE_URL = 'url';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    // The moment an approved post may go out from, the first slot at or after it sending it - null takes the next slot free, in the order the posts were prepared
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $plannedAt = null;

    // The networks ticked on the post's screen that it has no text for yet, written by SocialPublisher::addTargets() once the screen is saved - never stored
    /** @var list<string> */
    private array $addedNetworks = [];

    /** @var Collection<int, SocialPostTarget> */
    #[ORM\OneToMany(targetEntity: SocialPostTarget::class, mappedBy: 'post', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['network' => \SortDirection::Ascending])]
    private Collection $targets;

    public function __construct(
        #[ORM\Column(length: 64)]
        private string $sourceType,
        // The source's own id, or the sha1 of the url for a post prepared from one - an url does not fit an indexed column
        #[ORM\Column(length: 64)]
        private string $sourceId,
        string $title,
        #[ORM\Column(length: 2048)]
        private string $url,
        #[ORM\Column(length: 2048, nullable: true)]
        private ?string $imageUrl,
    ) {
        $this->title = mb_substr($title, 0, 255);
        $this->createdAt = new \DateTimeImmutable();
        $this->targets = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->title;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPlannedAt(): ?\DateTimeImmutable
    {
        return $this->plannedAt;
    }

    public function setPlannedAt(?\DateTimeImmutable $plannedAt): self
    {
        $this->plannedAt = $plannedAt;

        return $this;
    }

    // Hands every target not out yet to the next slot, a failed one being tried again there
    public function approve(): void
    {
        foreach ($this->targets as $target) {
            $target->approve();
        }
    }

    // Takes the post back out of the slots' queue, its approved targets waiting for a reading again
    public function unapprove(): void
    {
        foreach ($this->targets as $target) {
            $target->unapprove();
        }
    }

    // Whether a target still waits for a reading or a retry - what "Approve" is offered on
    public function isApprovable(): bool
    {
        return $this->targets->exists(static fn (int $key, SocialPostTarget $target): bool => $target->isPending() && SocialPostStatus::Approved !== $target->getStatus());
    }

    // Whether a target waits in the slots' queue - what "Unapprove" is offered on
    public function isApproved(): bool
    {
        return $this->targets->exists(static fn (int $key, SocialPostTarget $target): bool => SocialPostStatus::Approved === $target->getStatus());
    }

    // The networks the post goes to, whatever their texts became - what the post's screen ticks
    /** @return list<string> */
    public function getNetworks(): array
    {
        return $this->targets->map(static fn (SocialPostTarget $target): string => $target->getNetwork())->getValues();
    }

    // The networks ticked on the post's screen: a text not out yet on a network unticked is dropped, one published stays as the record of what went out, and a network newly ticked waits for its text
    /** @param list<string>|null $networks */
    public function setNetworks(?array $networks): self
    {
        $networks ??= [];
        foreach ($this->targets->toArray() as $target) {
            if ($target->isPending() && !\in_array($target->getNetwork(), $networks, true)) {
                $this->removeTarget($target);
            }
        }
        $this->addedNetworks = array_values(array_diff($networks, $this->getNetworks()));

        return $this;
    }

    // Hands the networks ticked with no text yet over once, to whoever writes their texts
    /** @return list<string> */
    public function takeAddedNetworks(): array
    {
        $networks = $this->addedNetworks;
        $this->addedNetworks = [];

        return $networks;
    }

    // The networks the post waits in the slots' queue for
    /** @return list<string> */
    public function getApprovedNetworks(): array
    {
        return $this->targets->filter(static fn (SocialPostTarget $target): bool => SocialPostStatus::Approved === $target->getStatus())->map(static fn (SocialPostTarget $target): string => $target->getNetwork())->getValues();
    }

    // The networks the post goes out or went out on - what a planned post takes a slot's place on, a draft or a failure taking none
    /** @return list<string> */
    public function getOutgoingNetworks(): array
    {
        return $this->targets->filter(static fn (SocialPostTarget $target): bool => \in_array($target->getStatus(), [SocialPostStatus::Approved, SocialPostStatus::Published], true))->map(static fn (SocialPostTarget $target): string => $target->getNetwork())->getValues();
    }

    /** @return Collection<int, SocialPostTarget> */
    public function getTargets(): Collection
    {
        return $this->targets;
    }

    public function addTarget(SocialPostTarget $target): self
    {
        if (!$this->targets->contains($target)) {
            $this->targets->add($target);
        }

        return $this;
    }

    public function removeTarget(SocialPostTarget $target): self
    {
        $this->targets->removeElement($target);

        return $this;
    }
}
