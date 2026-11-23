<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Mcp;

use c975L\ConfigBundle\Mcp\McpTool;
use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Entity\SocialSlot;
use c975L\SocialBundle\Repository\SocialSeriesRepository;
use c975L\SocialBundle\Repository\SocialSlotRepository;
use c975L\SocialBundle\Service\SocialSeriesGenerator;
use c975L\SocialBundle\Service\SocialSlotCalendar;
use c975L\SocialBundle\Service\SocialSlotRelayer;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Server\Exception\ToolCallException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

// The template week on the site's MCP endpoint, as on its screen (see SocialSlotsController): read with the slots each one meets, a slot written or taken off, a series' drafts laid again on its slots
class SocialSlotTools
{
    // Moments to come given for each slot read
    public const int NEXT = 3;

    public function __construct(
        private readonly SocialSlotRepository $slotRepository,
        private readonly SocialSeriesRepository $seriesRepository,
        private readonly SocialSlotCalendar $slotCalendar,
        private readonly SocialSlotRelayer $relayer,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly SocialMcpAccess $access,
        private readonly ClockInterface $clock,
    ) {
    }

    // The tools of the template week, under the social key of SocialMcpProvider
    /** @return iterable<McpTool> */
    public function getMcpTools(): iterable
    {
        yield new McpTool('social_slots', 'Template week', 'The slots of the template week: day, time, pace, the key an import names, the series prolonged on it, the slots it meets at the same minute and its next moments.', $this->list(...));
        yield new McpTool('social_slot_save', 'Write a slot', 'Adds a slot to the template week, or rewrites one given its id, every field as given; a series prolonged on it takes its next moments from it.', $this->save(...), true);
        yield new McpTool('social_slot_delete', 'Take a slot off', 'Takes a slot off the template week; its posts stay where they are.', $this->delete(...), true, true);
        yield new McpTool('social_slot_relay', 'Lay a series again', 'Moves the drafts to come of a series onto the free moments of its slots, in their order; approved posts and those gone out stay where they are.', $this->relay(...), true);
    }

    // The template week, by day then time, each slot with those it meets and its next moments
    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $this->access->guard();
        $slots = $this->slotRepository->findOrdered();
        $clashes = $this->slotCalendar->clashes($slots, $this->clock->now());

        return array_map(fn (SocialSlot $slot): array => [
            ...$this->summary($slot),
            'meets' => array_map(static fn (SocialSlot $other): ?int => $other->getId(), $clashes[(int) $slot->getId()] ?? []),
            'next' => array_map(static fn (\DateTimeImmutable $moment): string => $moment->format('Y-m-d H:i'), $this->slotCalendar->occurrences([$slot], $this->clock->now(), self::NEXT)),
        ], $slots);
    }

    // A slot added, or the one given rewritten with every field as given, refused as the screen's form would refuse it
    /**
     * @param string  $label    name of the slot
     * @param string  $pace     week, fortnight (one week in two from starts_on), month_rank (rank-th weekday of the month) or month_day (that date every month)
     * @param string  $time     HH:MM on a quarter of an hour, in the site's time
     * @param ?int    $weekday  1 Monday to 7 Sunday, unused by month_day
     * @param ?int    $rank     1 to 4, or -1 for the last, for month_rank
     * @param ?int    $monthDay 1 to 31 for month_day, the last day of a month too short for it
     * @param ?string $startsOn YYYY-MM-DD, a day of the first week of a fortnight
     * @param ?string $key      key an imported draft names the slot by, lowercase letters, digits and hyphens
     * @param ?int    $series   id of the series prolonged on the slot, none imported from a file
     * @param ?int    $slot     id of the slot to rewrite, a new one when empty
     *
     * @return array<string, mixed>
     */
    public function save(string $label, string $pace, string $time, ?int $weekday = null, ?int $rank = null, ?int $monthDay = null, ?string $startsOn = null, ?string $key = null, ?int $series = null, ?int $slot = null): array
    {
        $this->access->guard();
        $found = null === $slot ? new SocialSlot() : $this->found($slot);
        // The series and the day resolved first, a refusal leaving the slot untouched
        $served = null === $series ? null : $this->series($series);
        $start = $this->access->day($startsOn);
        $found
            ->setLabel($label)
            ->setPace($pace)
            ->setTime($time)
            ->setWeekday(SocialSlot::MONTH_DAY === $pace ? null : $weekday)
            ->setRank($rank)
            ->setMonthDay($monthDay)
            ->setStartsOn($start)
            ->setSlotKey($key)
            ->setSeries($served);
        $this->validate($found, null !== $slot);
        $this->entityManager->persist($found);
        $this->entityManager->flush();

        return $this->summary($found);
    }

    // A slot taken off the week, the posts planned on it left where they are
    /**
     * @param int $slot id of the slot
     *
     * @return array{deleted: int}
     */
    public function delete(int $slot): array
    {
        $this->access->guard();
        $this->entityManager->remove($this->found($slot));
        $this->entityManager->flush();

        return ['deleted' => $slot];
    }

    // A series' drafts to come laid again on its slots, how many moved returned
    /**
     * @param int $series id of the series
     *
     * @return array{series: int, moved: int}
     */
    public function relay(int $series): array
    {
        $this->access->guard();

        return ['series' => $series, 'moved' => $this->relayer->relay($this->series($series))];
    }

    // A slot by its id
    private function found(int $id): SocialSlot
    {
        $slot = $this->slotRepository->find($id);
        if (!$slot instanceof SocialSlot) {
            throw new ToolCallException(sprintf('No slot %d.', $id));
        }

        return $slot;
    }

    // A series by its id, one imported from a file refused: its moments are its file's
    private function series(int $id): SocialSeries
    {
        $series = $this->seriesRepository->find($id);
        if (!$series instanceof SocialSeries) {
            throw new ToolCallException(sprintf('No series %d.', $id));
        }
        if (SocialSeriesGenerator::MODE_IMPORT === $series->getMode()) {
            throw new ToolCallException(sprintf('The series %d is imported from a file: name the slot by its key in the file instead.', $id));
        }

        return $series;
    }

    // The slot checked by the constraints of its entity as the screen's form runs them, every violation named, a refused rewrite taken back off the managed row
    private function validate(SocialSlot $slot, bool $managed): void
    {
        $violations = $this->validator->validate($slot);
        if (0 === \count($violations)) {
            return;
        }
        if ($managed) {
            $this->entityManager->refresh($slot);
        }
        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
        }

        throw new ToolCallException(implode(' ', $messages));
    }

    // A slot as the tools give it
    /** @return array<string, mixed> */
    private function summary(SocialSlot $slot): array
    {
        return [
            'id' => $slot->getId(),
            'label' => $slot->getLabel(),
            'pace' => $slot->getPace(),
            'weekday' => $slot->getWeekday(),
            'time' => $slot->getTime(),
            'rank' => $slot->getRank(),
            'month_day' => $slot->getMonthDay(),
            'starts_on' => $slot->getStartsOn()?->format('Y-m-d'),
            'key' => $slot->getSlotKey(),
            'series' => null === $slot->getSeries() ? null : ['id' => $slot->getSeries()->getId(), 'title' => $slot->getSeries()->getTitle()],
        ];
    }
}
