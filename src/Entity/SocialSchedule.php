<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Entity;

use c975L\SocialBundle\Repository\SocialScheduleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

// A publication slot: every day at its time, one post of what its sources hand over, sent on its networks only - a scheduled task of its own (see SocialMaintenanceTaskProvider). While one is enabled, the hourly run on "social-publish-interval-hours" stands aside
#[ORM\Entity(repositoryClass: SocialScheduleRepository::class)]
#[ORM\Table(name: 'social_schedule')]
class SocialSchedule implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(type: Types::TIME_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $time = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    // A source type ("gallery_media") takes the whole source, "type:scope" ("gallery_media:3") one of its groups only - none at all takes every source in turn
    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $sources = [];

    // The networks this slot posts on, none meaning every configured one
    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $networks = [];

    // Put in the site's post template where it writes "{slot}"
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $text = null;

    public function __toString(): string
    {
        return (string) $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getTime(): ?\DateTimeImmutable
    {
        return $this->time;
    }

    public function setTime(?\DateTimeImmutable $time): self
    {
        $this->time = $time;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    /** @return list<string> */
    public function getSources(): array
    {
        return $this->sources;
    }

    /** @param array<int, string>|null $sources */
    public function setSources(?array $sources): self
    {
        $this->sources = array_values($sources ?? []);

        return $this;
    }

    // The sources as the publisher reads them: the scope ids of each source type, an empty list for a whole source - none at all for every source
    /** @return array<string, list<string>> */
    public function getScopesBySourceType(): array
    {
        $scopes = [];
        foreach ($this->sources as $source) {
            [$type, $scope] = explode(':', $source, 2) + [1 => null];
            $scopes[$type] ??= [];
            if (null !== $scope) {
                $scopes[$type][] = $scope;
            }
        }

        // A whole source picked beside some of its groups takes it whole
        foreach ($scopes as $type => $ids) {
            if (\in_array($type, $this->sources, true)) {
                $scopes[$type] = [];
            }
        }

        return $scopes;
    }

    /** @return list<string> */
    public function getNetworks(): array
    {
        return $this->networks;
    }

    /** @param array<int, string>|null $networks */
    public function setNetworks(?array $networks): self
    {
        $this->networks = array_values($networks ?? []);

        return $this;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(?string $text): self
    {
        $this->text = null === $text || '' === trim($text) ? null : trim($text);

        return $this;
    }
}
