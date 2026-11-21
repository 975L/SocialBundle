<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\SocialBundle\Entity\SocialSlot;

// The moments the slots of the template week fall on: every week, one week in two counted from the week it starts, a rank of the month or a date of the month. Nothing is read nor written, so the prolongation, the import and the week's screen share the same reckoning
class SocialSlotCalendar
{
    // How far the moments are looked for, two years of days: a slot never falling within them falls nowhere
    private const int MAX_DAYS = 731;

    // How far two slots are compared to say they meet: a whole year covers every month and both weeks of a fortnight
    private const int CLASH_DAYS = 371;

    // Whether the slot falls on that day, whatever its time
    public function occursOn(SocialSlot $slot, \DateTimeImmutable $day): bool
    {
        $date = (int) $day->format('j');
        if (SocialSlot::MONTH_DAY === $slot->getPace()) {
            return $date === min((int) $slot->getMonthDay(), (int) $day->format('t'));
        }
        if ((int) $day->format('N') !== $slot->getWeekday()) {
            return false;
        }

        return match ($slot->getPace()) {
            SocialSlot::FORTNIGHT => null !== $slot->getStartsOn() && 0 === $this->weeksBetween($slot->getStartsOn(), $day) % 2,
            SocialSlot::MONTH_RANK => SocialSlot::LAST === $slot->getRank() ? $date + 7 > (int) $day->format('t') : intdiv($date - 1, 7) + 1 === $slot->getRank(),
            default => true,
        };
    }

    // The slot's moment on that day
    public function at(SocialSlot $slot, \DateTimeImmutable $day): \DateTimeImmutable
    {
        [$hour, $minute] = $slot->getHourMinute();

        return $day->setTime($hour, $minute);
    }

    // The next moments of the slots after $after, the earliest first, a minute two slots share counted once - at most $count, none past $until
    /**
     * @param list<SocialSlot> $slots
     *
     * @return list<\DateTimeImmutable>
     */
    public function occurrences(array $slots, \DateTimeImmutable $after, int $count, ?\DateTimeImmutable $until = null): array
    {
        $moments = [];
        $day = $after->setTime(0, 0);
        for ($i = 0; $i < self::MAX_DAYS && \count($moments) < $count && (null === $until || $day <= $until); ++$i, $day = $day->modify('+1 day')) {
            $found = [];
            foreach ($slots as $slot) {
                $moment = $this->at($slot, $day);
                if ($moment > $after && (null === $until || $moment <= $until) && $this->occursOn($slot, $day)) {
                    $found[$moment->format('H:i')] = $moment;
                }
            }
            ksort($found);
            array_push($moments, ...array_values($found));
        }

        return \array_slice($moments, 0, max(0, $count));
    }

    // The slot a moment is an occurrence of, none for a moment off every slot
    /** @param list<SocialSlot> $slots */
    public function slotAt(array $slots, \DateTimeImmutable $moment): ?SocialSlot
    {
        foreach ($slots as $slot) {
            if ($slot->getTime() === $moment->format('H:i') && $this->occursOn($slot, $moment)) {
                return $slot;
            }
        }

        return null;
    }

    // The slots each one meets at the same minute within a year from $from, by id - a slot meeting none left out
    /**
     * @param list<SocialSlot> $slots
     *
     * @return array<int, list<SocialSlot>>
     */
    public function clashes(array $slots, \DateTimeImmutable $from): array
    {
        $clashes = [];
        foreach ($slots as $index => $slot) {
            foreach (\array_slice($slots, $index + 1) as $other) {
                if ($slot->getTime() === $other->getTime() && $this->meet($slot, $other, $from)) {
                    $clashes[(int) $slot->getId()][] = $other;
                    $clashes[(int) $other->getId()][] = $slot;
                }
            }
        }

        return $clashes;
    }

    // Whether two slots fall on a same day within a year
    private function meet(SocialSlot $slot, SocialSlot $other, \DateTimeImmutable $from): bool
    {
        $day = $from->setTime(0, 0);
        for ($i = 0; $i < self::CLASH_DAYS; ++$i, $day = $day->modify('+1 day')) {
            if ($this->occursOn($slot, $day) && $this->occursOn($other, $day)) {
                return true;
            }
        }

        return false;
    }

    // Whole weeks from the Monday of $start to the Monday of $day, counted on the calendar rather than in seconds, which a change of time would shift
    private function weeksBetween(\DateTimeImmutable $start, \DateTimeImmutable $day): int
    {
        $first = $start->setTime(12, 0)->modify('monday this week');
        $current = $day->setTime(12, 0)->modify('monday this week');
        $days = (int) $first->diff($current)->days;

        return intdiv($days, 7);
    }
}
