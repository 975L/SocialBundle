<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Entity\SocialSlot;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Repository\SocialSlotRepository;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// The plan of the coming days up to a day, read before the month's drafts are approved: every moment of the template week with the post holding it or left empty, the posts off the slots as well, how many go out each day, what a network would refuse in each post, and for each series drawing from the contents on the slots how many slots it still has to fill against the contents left
class SocialSlotPlan
{
    // The most moments of the slots read, a busy week over three months
    private const int MAX_MOMENTS = 1000;

    // The most free contents counted per series: past it, a series has enough
    public const int FREE_LIMIT = 100;

    public function __construct(
        private readonly SocialSlotRepository $slotRepository,
        private readonly SocialPostRepository $postRepository,
        private readonly SocialSlotCalendar $slotCalendar,
        private readonly SocialMediaChecker $mediaChecker,
        private readonly SocialPublisher $socialPublisher,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    // The moments from now to the end of $until in their order, then the series on the slots drawing from the contents
    /** @return array{until: string, moments: list<array<string, mixed>>, series: list<array<string, mixed>>} */
    public function read(\DateTimeImmutable $until): array
    {
        $now = $this->clock->now();
        $end = $until->setTime(23, 59, 59);
        $slots = $this->slotRepository->findOrdered();

        $posts = [];
        foreach ($this->postRepository->findPlannedBetween($now, $end->modify('+1 second')) as $post) {
            $posts[$post->getPlannedAt()->format('Y-m-d H:i')] = $post;
        }

        // Every moment of the slots, then the posts off them, each with the slot it falls on
        $moments = [];
        foreach ($this->slotCalendar->occurrences($slots, $now, self::MAX_MOMENTS, $end) as $moment) {
            $moments[$moment->format('Y-m-d H:i')] = $this->slotCalendar->slotAt($slots, $moment);
        }
        foreach ($posts as $key => $post) {
            $moments[$key] ??= $this->slotCalendar->slotAt($slots, $post->getPlannedAt());
        }
        ksort($moments);

        $perDay = array_count_values(array_map(static fn (string $key): string => substr($key, 0, 10), array_keys($posts)));
        $rows = [];
        $empty = [];
        foreach ($moments as $key => $slot) {
            $post = $posts[$key] ?? null;
            if (null === $post && null !== $slot?->getSeries()) {
                $empty[(int) $slot->getSeries()->getId()] = ($empty[(int) $slot->getSeries()->getId()] ?? 0) + 1;
            }
            $rows[] = $this->row($key, $slot, $post, $perDay[substr($key, 0, 10)] ?? 0);
        }

        return ['until' => $end->format('Y-m-d'), 'moments' => $rows, 'series' => $this->series($slots, $empty)];
    }

    // One moment: its slot, its series, its post or none, and how many posts go out that day
    /** @return array<string, mixed> */
    private function row(string $key, ?SocialSlot $slot, ?SocialPost $post, int $dayCount): array
    {
        $series = $post?->getSeries() ?? $slot?->getSeries();

        return [
            'at' => $key,
            'slot' => null === $slot ? null : ['id' => $slot->getId(), 'label' => $slot->getLabel(), 'key' => $slot->getSlotKey()],
            'series' => null === $series ? null : ['id' => $series->getId(), 'title' => $series->getTitle(), 'mode' => $series->getMode()],
            'post' => null === $post ? null : $this->post($post),
            'dayCount' => $dayCount,
        ];
    }

    // A post as the plan shows it: its state, networks and what a network would refuse in its medias
    /** @return array<string, mixed> */
    private function post(SocialPost $post): array
    {
        $problems = [];
        foreach ($this->mediaChecker->check($post) as $network => $messages) {
            foreach ($messages as $message) {
                $problems[] = $network . ': ' . $message->trans($this->translator);
            }
        }

        return [
            'id' => $post->getId(),
            'title' => $post->getTitle(),
            'state' => $post->getState(),
            'networks' => array_values(array_map(static fn ($target): string => $target->getNetwork(), $post->getTargets()->toArray())),
            'problems' => $problems,
        ];
    }

    // The series drawing from the contents on the slots: the slots they still have to fill, the contents left (up to FREE_LIMIT, null when a source picked hands them out one by one or by date) and whether they run out
    /**
     * @param list<SocialSlot> $slots
     * @param array<int, int>  $empty
     *
     * @return list<array<string, mixed>>
     */
    private function series(array $slots, array $empty): array
    {
        $found = [];
        foreach ($slots as $slot) {
            $series = $slot->getSeries();
            if (null !== $series && SocialSeriesGenerator::MODE_SOURCE === $series->getMode()) {
                $found[(int) $series->getId()] = $series;
            }
        }

        return array_values(array_map(function (SocialSeries $series) use ($empty): array {
            $toFill = $empty[(int) $series->getId()] ?? 0;
            $free = $this->socialPublisher->countsFreeContents($series->getSources()) ? \count($this->socialPublisher->freeContentsOf($series->getSources(), self::FREE_LIMIT)) : null;

            return ['id' => $series->getId(), 'title' => $series->getTitle(), 'toFill' => $toFill, 'free' => $free, 'runsOut' => null !== $free && $free < $toFill];
        }, $found));
    }
}
