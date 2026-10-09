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
use c975L\UiBundle\Model\SocialContent;
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

    // The source type of a post written on its screen, its own text being its content
    public const string SOURCE_MANUAL = 'manual';

    // How much of the text a post written on its screen is named by
    private const int TITLE_LENGTH = 80;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    // A post written on its screen: the text every network's own is cut from, rephrased or translated with Donovan
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $text = null;

    // Whether the text changed since the post was read, its networks' texts then written from it again - never stored
    private bool $textChanged = false;

    // The networks ticked on the post's screen that it has no text for yet, written by SocialPublisher::addTargets() once the screen is saved - never stored
    /** @var list<string> */
    private array $addedNetworks = [];

    // The series it was generated in, prolonged from its last post - none for a post prepared on its own, or once its series is deleted
    #[ORM\ManyToOne(targetEntity: SocialSeries::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SocialSeries $series = null;

    /** @var Collection<int, SocialMedia> */
    #[ORM\OneToMany(targetEntity: SocialMedia::class, mappedBy: 'post', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => \SortDirection::Ascending, 'id' => \SortDirection::Ascending])]
    private Collection $medias;

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
        // The moment the post goes out once approved, to the quarter of an hour (the planned run) - every post has one, a draft holding its place on the calendar
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $plannedAt,
    ) {
        $this->title = mb_substr($title, 0, 255);
        $this->createdAt = new \DateTimeImmutable();
        $this->targets = new ArrayCollection();
        $this->medias = new ArrayCollection();
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

    // A post written on its screen links where it says, or nowhere
    public function setUrl(?string $url): self
    {
        $this->url = trim((string) $url);

        return $this;
    }

    public function isManual(): bool
    {
        return self::SOURCE_MANUAL === $this->sourceType;
    }

    // Its networks' texts cut from its own text rather than written from its content: a post written on its screen, or one of a series given its text and a content's picture
    public function hasOwnText(): bool
    {
        return $this->isManual() || null !== $this->text;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    // A post written on its screen named by the first line of its text, cut, the list and the calendar reading it there - one taken from a content keeps that content's title
    public function setText(?string $text): self
    {
        $text = null === $text || '' === trim($text) ? null : trim($text);
        $this->textChanged = $this->textChanged || $text !== $this->text;
        $this->text = $text;

        $firstLine = trim(strtok((string) $text, "\n") ?: '');
        if ('' !== $firstLine && $this->isManual()) {
            $this->title = mb_strlen($firstLine) > self::TITLE_LENGTH ? mb_substr($firstLine, 0, self::TITLE_LENGTH - 1) . '…' : $firstLine;
        }

        return $this;
    }

    // Hands over once whether the text changed, to whoever writes the networks' texts from it
    public function takeTextChanged(): bool
    {
        $changed = $this->textChanged;
        $this->textChanged = false;

        return $changed;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPlannedAt(): \DateTimeImmutable
    {
        return $this->plannedAt;
    }

    // A field emptied on the post's screen keeps the moment it had: a post is never left without one
    public function setPlannedAt(?\DateTimeImmutable $plannedAt): self
    {
        $this->plannedAt = $plannedAt ?? $this->plannedAt;

        return $this;
    }

    // Hands every target not out yet to the planned run, a failed one being tried again at the post's moment
    public function approve(): void
    {
        foreach ($this->targets as $target) {
            $target->approve();
        }
    }

    // Takes the post back to a draft, its approved targets waiting for a reading again at the same moment
    public function unapprove(): void
    {
        foreach ($this->targets as $target) {
            $target->unapprove();
        }
    }

    // Ties the post to another content of its source, the one it held freed as soon as it is saved - refused once it went out anywhere, the networks showing the picture it went out with
    public function changeContent(SocialContent $content): bool
    {
        if ($this->hasGoneOut()) {
            return false;
        }

        $this->sourceId = $content->sourceId;
        $this->title = mb_substr($content->title, 0, 255);
        $this->url = $content->url;
        $this->imageUrl = $content->imageUrl;

        return true;
    }

    public function getSeries(): ?SocialSeries
    {
        return $this->series;
    }

    public function setSeries(?SocialSeries $series): self
    {
        $this->series = $series;

        return $this;
    }

    // What the calendar's colour says: gone out everywhere, refused somewhere, waiting for its moment, or for a reading
    public function getState(): string
    {
        return match (true) {
            $this->isPublished() => 'published',
            $this->targets->exists(static fn (int $key, SocialPostTarget $target): bool => SocialPostStatus::Failed === $target->getStatus()) => 'failed',
            $this->isApproved() => 'approved',
            default => 'draft',
        };
    }

    // Whether it went out on any of its networks
    public function hasGoneOut(): bool
    {
        return $this->targets->exists(static fn (int $key, SocialPostTarget $target): bool => null !== $target->getPublishedAt());
    }

    // Whether a target still waits for a reading or a retry - what "Approve" is offered on
    public function isApprovable(): bool
    {
        return $this->targets->exists(static fn (int $key, SocialPostTarget $target): bool => $target->isPending() && SocialPostStatus::Approved !== $target->getStatus());
    }

    // Whether it went out on every network it has, a post with none never having gone anywhere
    public function isPublished(): bool
    {
        return !$this->targets->isEmpty() && !$this->targets->exists(static fn (int $key, SocialPostTarget $target): bool => $target->isPending());
    }

    // Whether a target waits for its moment - what "Unapprove" is offered on
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

    // The networks the post waits for its moment on
    /** @return list<string> */
    public function getApprovedNetworks(): array
    {
        return $this->targets->filter(static fn (SocialPostTarget $target): bool => SocialPostStatus::Approved === $target->getStatus())->map(static fn (SocialPostTarget $target): string => $target->getNetwork())->getValues();
    }

    /** @return Collection<int, SocialPostTarget> */
    public function getTargets(): Collection
    {
        return $this->targets;
    }

    // The picture the post is shown with on the screens: its first own picture, the content's otherwise
    public function getThumbnailUrl(): ?string
    {
        foreach ($this->medias as $media) {
            if (!$media->isVideo() && null !== $media->getPublicPath()) {
                return $media->getPublicPath();
            }
        }

        return $this->imageUrl;
    }

    /** @return Collection<int, SocialMedia> */
    public function getMedias(): Collection
    {
        return $this->medias;
    }

    public function addMedia(SocialMedia $media): self
    {
        if (!$this->medias->contains($media)) {
            $media->setPost($this);
            $this->medias->add($media);
        }

        return $this;
    }

    public function removeMedia(SocialMedia $media): self
    {
        $this->medias->removeElement($media);

        return $this;
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
