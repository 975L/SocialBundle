<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

// A picture or a video a post goes out with, in the order given: uploaded here (one JPEG for a picture, hashed and downscaled, see SocialMediaUploadListener), or a file of the site's own taken as it is (a gallery's, by its path) - nothing copied then
#[ORM\Entity]
#[ORM\Table(name: 'social_media')]
#[Vich\Uploadable]
class SocialMedia
{
    // The video types the networks take, what an upload is kept as rather than turned into a JPEG
    public const array VIDEO_TYPES = ['video/mp4', 'video/quicktime'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Stored by Vich under public/ (see SocialMediaNamer), deleted with the media or replaced with the next upload
    #[Vich\UploadableField(mapping: 'social_media', fileNameProperty: 'filename', size: 'size', mimeType: 'mimeType')]
    private ?File $file = null;

    // The path under public/ - an upload's, or the site's own file it was picked from
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $filename = null;

    // Whether the file is the site's own rather than uploaded here: never deleted with the media nor purged
    #[ORM\Column(options: ['default' => false])]
    private bool $reference = false;

    #[ORM\Column(nullable: true)]
    private ?int $size = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(nullable: true)]
    private ?int $width = null;

    #[ORM\Column(nullable: true)]
    private ?int $height = null;

    // In seconds, a video's alone - null where ffprobe could not tell
    #[ORM\Column(nullable: true)]
    private ?float $duration = null;

    // What a network reading it aloud says of it
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $alt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    // Changed with every upload, Vich saving a new file only when a mapped column changes
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: SocialPost::class, inversedBy: 'medias')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private ?SocialPost $post = null,
    ) {
    }

    // A file of the site's own, by its path under public/, taken as it is
    public static function reference(SocialPost $post, string $path, string $mimeType, ?int $width = null, ?int $height = null, ?float $duration = null): self
    {
        $media = new self($post);
        $media->filename = ltrim($path, '/');
        $media->reference = true;
        $media->mimeType = $mimeType;
        $media->setDimensions($width, $height, $duration);

        return $media;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPost(): ?SocialPost
    {
        return $this->post;
    }

    public function setPost(?SocialPost $post): self
    {
        $this->post = $post;

        return $this;
    }

    public function getFile(): ?File
    {
        return $this->file;
    }

    // A new upload replaces the file, under a name of its own: a reference stays one until Vich has left its file alone (see SocialMediaUploadListener)
    public function setFile(?File $file): self
    {
        $this->file = $file;
        if (null !== $file) {
            $this->updatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(?string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    // Where the browser and the networks read it
    public function getPublicPath(): ?string
    {
        return null === $this->filename ? null : '/' . $this->filename;
    }

    public function isReference(): bool
    {
        return $this->reference;
    }

    public function setReference(bool $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function isVideo(): bool
    {
        return str_starts_with((string) $this->mimeType, 'video/');
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function setSize(?int $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): self
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    // Measured off the stored file once it is written (see SocialMediaUploadListener)
    public function setDimensions(?int $width, ?int $height, ?float $duration = null): self
    {
        $this->width = $width;
        $this->height = $height;
        $this->duration = $duration;

        return $this;
    }

    public function getDuration(): ?float
    {
        return $this->duration;
    }

    // The width over the height, null while either is unknown
    public function getRatio(): ?float
    {
        return null === $this->width || null === $this->height || 0 === $this->height ? null : $this->width / $this->height;
    }

    public function getAlt(): ?string
    {
        return $this->alt;
    }

    public function setAlt(?string $alt): self
    {
        $this->alt = null === $alt || '' === trim($alt) ? null : trim($alt);

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(?int $position): self
    {
        $this->position = (int) $position;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
