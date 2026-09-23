<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Entity;

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

    /** @var Collection<int, SocialPostTarget> */
    #[ORM\OneToMany(targetEntity: SocialPostTarget::class, mappedBy: 'post', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['network' => 'ASC'])]
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
