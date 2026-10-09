<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Entity;

use c975L\SocialBundle\Repository\SocialSeriesRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// A series of drafts as it was asked for (see SocialSeriesGenerator): its pace, its texts and its contents, kept so it is prolonged with the same settings and its end announced by email before it comes
#[ORM\Entity(repositoryClass: SocialSeriesRepository::class)]
#[ORM\Table(name: 'social_series')]
class SocialSeries implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    // The series' picture or video, stored once and referenced by each draft: path, MIME type, width, height, duration and size
    /** @var ?array{0: string, 1: string, 2: ?int, 3: ?int, 4: ?float, 5: int} */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $media = null;

    // The last planned moment its ending was announced for - announced once, again only once it is prolonged
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $notifiedFor = null;

    /**
     * @param list<int>    $weekdays
     * @param list<string> $sources
     * @param list<string> $networks
     */
    public function __construct(
        #[ORM\Column(length: 255)]
        private string $title,
        #[ORM\Column]
        private int $count,
        #[ORM\Column(length: 16)]
        private string $frequency,
        // Named apart: INTERVAL is a reserved word in MySQL and MariaDB
        #[ORM\Column(name: 'pace_interval')]
        private int $interval,
        #[ORM\Column(type: Types::JSON)]
        private array $weekdays,
        #[ORM\Column(length: 16)]
        private string $mode,
        #[ORM\Column(type: Types::TEXT)]
        private string $text,
        #[ORM\Column(type: Types::JSON)]
        private array $sources,
        #[ORM\Column(type: Types::JSON)]
        private array $networks,
    ) {
        $this->title = mb_substr($title, 0, 255);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->title;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function getFrequency(): string
    {
        return $this->frequency;
    }

    public function getInterval(): int
    {
        return $this->interval;
    }

    /** @return list<int> */
    public function getWeekdays(): array
    {
        return $this->weekdays;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getText(): string
    {
        return $this->text;
    }

    /** @return list<string> */
    public function getSources(): array
    {
        return $this->sources;
    }

    /** @return list<string> */
    public function getNetworks(): array
    {
        return $this->networks;
    }

    /** @return ?array{0: string, 1: string, 2: ?int, 3: ?int, 4: ?float, 5: int} */
    public function getMedia(): ?array
    {
        return $this->media;
    }

    /** @param ?array{0: string, 1: string, 2: ?int, 3: ?int, 4: ?float, 5: int} $media */
    public function setMedia(?array $media): self
    {
        $this->media = $media;

        return $this;
    }

    public function getNotifiedFor(): ?\DateTimeImmutable
    {
        return $this->notifiedFor;
    }

    public function setNotifiedFor(?\DateTimeImmutable $notifiedFor): self
    {
        $this->notifiedFor = $notifiedFor;

        return $this;
    }
}
