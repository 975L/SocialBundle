<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Entity\SocialSlot;
use c975L\SocialBundle\Service\SocialSlotCalendar;
use PHPUnit\Framework\TestCase;

class SocialSlotCalendarTest extends TestCase
{
    // A slot of the template week, its id given for the clashes
    private function slot(string $pace, ?int $weekday, string $time, int $id = 1, ?int $rank = null, ?int $monthDay = null, ?string $startsOn = null): SocialSlot
    {
        $slot = new SocialSlot()->setLabel('item-' . $id)->setPace($pace)->setWeekday($weekday)->setTime($time)->setRank($rank)->setMonthDay($monthDay)
            ->setStartsOn(null === $startsOn ? null : new \DateTimeImmutable($startsOn));
        new \ReflectionProperty(SocialSlot::class, 'id')->setValue($slot, $id);

        return $slot;
    }

    /**
     * @param list<\DateTimeImmutable> $moments
     *
     * @return list<string>
     */
    private function formatted(array $moments): array
    {
        return array_map(static fn (\DateTimeImmutable $moment): string => $moment->format('Y-m-d H:i'), $moments);
    }

    // Every week on its day, strictly after the moment given: a Monday at the slot's very time is not taken again
    public function testWeekly(): void
    {
        $moments = new SocialSlotCalendar()->occurrences([$this->slot(SocialSlot::WEEK, 1, '08:30')], new \DateTimeImmutable('2026-11-23 08:30'), 3);

        $this->assertSame(['2026-11-30 08:30', '2026-12-07 08:30', '2026-12-14 08:30'], $this->formatted($moments));
    }

    // One week in two counted from its first week, across the 53rd ISO week of 2026 which a parity of the week number would get wrong
    public function testFortnightAcrossYearEnd(): void
    {
        $slot = $this->slot(SocialSlot::FORTNIGHT, 1, '10:00', startsOn: '2026-12-21');

        $this->assertSame(['2026-12-21 10:00', '2027-01-04 10:00', '2027-01-18 10:00'], $this->formatted(new SocialSlotCalendar()->occurrences([$slot], new \DateTimeImmutable('2026-12-20'), 3)));
    }

    // The second Tuesday and the last Friday of the month
    public function testMonthRank(): void
    {
        $calendar = new SocialSlotCalendar();
        $second = $this->slot(SocialSlot::MONTH_RANK, 2, '18:00', rank: 2);
        $last = $this->slot(SocialSlot::MONTH_RANK, 5, '18:00', rank: SocialSlot::LAST);

        $this->assertSame(['2026-12-08 18:00', '2027-01-12 18:00'], $this->formatted($calendar->occurrences([$second], new \DateTimeImmutable('2026-12-01'), 2)));
        $this->assertSame(['2026-12-25 18:00', '2027-01-29 18:00'], $this->formatted($calendar->occurrences([$last], new \DateTimeImmutable('2026-12-01'), 2)));
    }

    // The 31st falls on the last day of a shorter month
    public function testMonthDay(): void
    {
        $moments = new SocialSlotCalendar()->occurrences([$this->slot(SocialSlot::MONTH_DAY, null, '09:00', monthDay: 31)], new \DateTimeImmutable('2027-01-01'), 3);

        $this->assertSame(['2027-01-31 09:00', '2027-02-28 09:00', '2027-03-31 09:00'], $this->formatted($moments));
    }

    // Several slots merged in order, a minute two of them share counted once, none past the end
    public function testMergedUntil(): void
    {
        $slots = [$this->slot(SocialSlot::WEEK, 5, '20:30', 1), $this->slot(SocialSlot::WEEK, 1, '20:30', 2), $this->slot(SocialSlot::WEEK, 1, '20:30', 3)];
        $moments = new SocialSlotCalendar()->occurrences($slots, new \DateTimeImmutable('2026-11-23'), 10, new \DateTimeImmutable('2026-12-01'));

        $this->assertSame(['2026-11-23 20:30', '2026-11-27 20:30', '2026-11-30 20:30'], $this->formatted($moments));
    }

    // Two slots at the same minute meet unless their weeks of a fortnight differ; another time never does
    public function testClashes(): void
    {
        $even = $this->slot(SocialSlot::FORTNIGHT, 6, '10:00', 1, startsOn: '2026-11-23');
        $odd = $this->slot(SocialSlot::FORTNIGHT, 6, '10:00', 2, startsOn: '2026-11-30');
        $weekly = $this->slot(SocialSlot::WEEK, 6, '10:00', 3);
        $later = $this->slot(SocialSlot::WEEK, 6, '10:15', 4);
        $clashes = new SocialSlotCalendar()->clashes([$even, $odd, $weekly, $later], new \DateTimeImmutable('2026-11-23'));

        $this->assertSame([3], array_map(static fn (SocialSlot $slot): ?int => $slot->getId(), $clashes[1]));
        $this->assertSame([1, 2], array_map(static fn (SocialSlot $slot): ?int => $slot->getId(), $clashes[3]));
        $this->assertArrayNotHasKey(4, $clashes);
    }

    // The slot a moment falls on, none off the template week
    public function testSlotAt(): void
    {
        $slot = $this->slot(SocialSlot::WEEK, 3, '08:30');
        $calendar = new SocialSlotCalendar();

        $this->assertSame($slot, $calendar->slotAt([$slot], new \DateTimeImmutable('2026-11-25 08:30')));
        $this->assertNull($calendar->slotAt([$slot], new \DateTimeImmutable('2026-11-25 09:00')));
    }
}
