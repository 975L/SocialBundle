<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Entity;

use c975L\SocialBundle\Repository\SocialSlotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

// A slot of the site's template week: a day and a time posts go out at, every week, one week in two, or once a month by rank or by date. A series pointing to slots is prolonged on them rather than at the time of its last post, and an imported draft naming one by its key takes its time (see SocialSlotCalendar)
#[ORM\Entity(repositoryClass: SocialSlotRepository::class)]
#[ORM\Table(name: 'social_slot')]
#[UniqueEntity(fields: ['slotKey'], ignoreNull: true)]
class SocialSlot implements \Stringable
{
    public const string WEEK = 'week';

    // One week in two, counted from the week of its start
    public const string FORTNIGHT = 'fortnight';

    // The first, second, third, fourth or last given day of the month
    public const string MONTH_RANK = 'month_rank';

    // The same date every month, the last day of a month too short for it
    public const string MONTH_DAY = 'month_day';

    public const array PACES = [self::WEEK, self::FORTNIGHT, self::MONTH_RANK, self::MONTH_DAY];

    // The last given day of the month, as a rank
    public const int LAST = -1;

    public const array RANKS = [1, 2, 3, 4, self::LAST];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $label = '';

    #[ORM\Column(length: 16)]
    #[Assert\Length(max: 16)]
    #[Assert\Choice(choices: self::PACES)]
    private string $pace = self::WEEK;

    // ISO day of the week, 1 for Monday - none for a slot on a date of the month
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 7)]
    private ?int $weekday = 1;

    // A quarter of an hour, the step the planned run sends posts at (see SocialPlanner)
    #[ORM\Column(length: 5)]
    #[Assert\Length(max: 5)]
    #[Assert\Regex(pattern: '/^([01]\d|2[0-3]):(00|15|30|45)$/')]
    private string $time = '09:00';

    #[ORM\Column(name: 'month_rank', nullable: true)]
    #[Assert\Choice(choices: self::RANKS)]
    private ?int $rank = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 31)]
    private ?int $monthDay = null;

    // The first week of a slot one week in two
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startsOn = null;

    // What an imported draft names it by ("slot" in c975l:social:draft's file)
    #[ORM\Column(length: 64, nullable: true, unique: true)]
    #[Assert\Length(max: 64)]
    #[Assert\Regex(pattern: '/^[a-z0-9][a-z0-9-]*$/')]
    private ?string $slotKey = null;

    // The series prolonged on it - none for a slot only imports name
    #[ORM\ManyToOne(targetEntity: SocialSeries::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SocialSeries $series = null;

    public function __toString(): string
    {
        return $this->label;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(?string $label): self
    {
        $this->label = trim((string) $label);

        return $this;
    }

    public function getPace(): string
    {
        return $this->pace;
    }

    public function setPace(?string $pace): self
    {
        $this->pace = (string) $pace;

        return $this;
    }

    public function getWeekday(): ?int
    {
        return $this->weekday;
    }

    public function setWeekday(?int $weekday): self
    {
        $this->weekday = $weekday;

        return $this;
    }

    public function getTime(): string
    {
        return $this->time;
    }

    public function setTime(?string $time): self
    {
        $this->time = trim((string) $time);

        return $this;
    }

    public function getRank(): ?int
    {
        return $this->rank;
    }

    public function setRank(?int $rank): self
    {
        $this->rank = $rank;

        return $this;
    }

    public function getMonthDay(): ?int
    {
        return $this->monthDay;
    }

    public function setMonthDay(?int $monthDay): self
    {
        $this->monthDay = $monthDay;

        return $this;
    }

    public function getStartsOn(): ?\DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function setStartsOn(?\DateTimeImmutable $startsOn): self
    {
        $this->startsOn = $startsOn;

        return $this;
    }

    public function getSlotKey(): ?string
    {
        return $this->slotKey;
    }

    public function setSlotKey(?string $slotKey): self
    {
        $slotKey = trim((string) $slotKey);
        $this->slotKey = '' === $slotKey ? null : $slotKey;

        return $this;
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

    // The hour and minute it goes out at
    /** @return array{int, int} */
    public function getHourMinute(): array
    {
        [$hour, $minute] = array_map(intval(...), explode(':', $this->time . ':0'));

        return [$hour, $minute];
    }

    // What its pace needs: a day of the week but on a date, a rank for a rank of the month, a date for a date of the month, a first week for one week in two
    #[Assert\Callback]
    public function validatePace(ExecutionContextInterface $context): void
    {
        $missing = match ($this->pace) {
            self::MONTH_DAY => null === $this->monthDay ? 'monthDay' : null,
            self::MONTH_RANK => null === $this->rank ? 'rank' : (null === $this->weekday ? 'weekday' : null),
            self::FORTNIGHT => null === $this->startsOn ? 'startsOn' : (null === $this->weekday ? 'weekday' : null),
            default => null === $this->weekday ? 'weekday' : null,
        };
        if (null !== $missing) {
            $context->buildViolation('label.social_slot_missing')->setTranslationDomain('social')->atPath($missing)->addViolation();
        }
    }
}
