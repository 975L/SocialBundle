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
use c975L\SocialBundle\Entity\SocialSchedule;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Repository\SocialScheduleRepository;

// Where each approved post goes out, one rule for the runs and the calendar, so the calendar shows exactly what will happen. A post planned for a moment goes out at that moment, to the quarter of an hour (the planned run, every 15 minutes); the unplanned ones wait in the queue, in the order they were prepared, for the next slot posting on one of their networks - a slot sending a post on its own networks only, the rest of it waiting for a slot posting there. A post planned on a slot's quarter of an hour takes that slot's place
class SocialPlanner
{
    // The step the planned run goes by, and the calendar's grid
    public const int QUARTER = 900;

    public function __construct(
        private readonly SocialPostRepository $postRepository,
        private readonly SocialScheduleRepository $scheduleRepository,
    ) {
    }

    // The unplanned post a slot posting on $networks sends among $posts, null when none waits for it. $remaining narrows each post to the networks not sent yet, by post id - what a projection has already placed
    /**
     * @param list<SocialPost>              $posts
     * @param list<string>                  $networks
     * @param array<int, list<string>>|null $remaining
     */
    public function pick(array $posts, array $networks, ?array $remaining = null): ?SocialPost
    {
        foreach ($posts as $post) {
            if (null === $post->getPlannedAt() && [] !== array_intersect($this->networksOf($post, $remaining), $networks)) {
                return $post;
            }
        }

        return null;
    }

    // Whether a post planned on the quarter of an hour starting at $at, going out or gone out on one of $networks, takes the slot's place
    /**
     * @param list<SocialPost> $posts
     * @param list<string>     $networks
     */
    public function isTaken(array $posts, array $networks, \DateTimeImmutable $at): bool
    {
        $end = $at->modify('+' . self::QUARTER . ' seconds');
        foreach ($posts as $post) {
            $planned = $post->getPlannedAt();
            if (null !== $planned && $planned >= $at && $planned < $end && [] !== array_intersect($post->getOutgoingNetworks(), $networks)) {
                return true;
            }
        }

        return false;
    }

    // The first quarter of an hour the planned run reaches after $now, where a post planned in the past goes out
    public function nextQuarter(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->setTimestamp((intdiv($now->getTimestamp(), self::QUARTER) + 1) * self::QUARTER);
    }

    // What goes out from $from to $to, in time order: every planned post at its moment ("slot" null), and every slot occurrence with the unplanned post it will send and on which networks - the post null where nothing waits, "taken" where a planned post stands in its place. $connected are the networks a slot without its own may post on
    /**
     * @param list<string> $connected
     *
     * @return list<array{at: \DateTimeImmutable, slot: ?SocialSchedule, networks: list<string>, post: ?SocialPost, sent: list<string>, taken: bool}>
     */
    public function project(\DateTimeImmutable $from, \DateTimeImmutable $to, array $connected, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $posts = $this->postRepository->findApproved();
        $planned = array_values(array_filter($posts, static fn (SocialPost $post): bool => null !== $post->getPlannedAt()));
        $queue = array_values(array_filter($posts, static fn (SocialPost $post): bool => null === $post->getPlannedAt()));

        $projection = [];
        foreach ($planned as $post) {
            $at = max($post->getPlannedAt(), $this->nextQuarter($now));
            if ($at >= $from && $at < $to) {
                $networks = $post->getApprovedNetworks();
                $projection[] = ['at' => $at, 'slot' => null, 'networks' => $networks, 'post' => $post, 'sent' => $networks, 'taken' => false];
            }
        }

        $remaining = [];
        foreach ($queue as $post) {
            $remaining[(int) $post->getId()] = $post->getApprovedNetworks();
        }

        // Played from now even for a month ahead: what the slots before it send is what is left for it
        foreach ($this->occurrences($now, $to) as [$at, $slot]) {
            $networks = $slot->resolveNetworks($connected);
            $taken = $this->isTaken($planned, $networks, $at);
            $post = [] === $networks || $taken ? null : $this->pick($queue, $networks, $remaining);
            $sent = [];
            if (null !== $post) {
                $id = (int) $post->getId();
                $sent = array_values(array_intersect($remaining[$id], $networks));
                $remaining[$id] = array_values(array_diff($remaining[$id], $networks));
                if ([] === $remaining[$id]) {
                    $queue = array_values(array_filter($queue, static fn (SocialPost $other): bool => $other !== $post));
                }
            }

            if ($at >= $from) {
                $projection[] = ['at' => $at, 'slot' => $slot, 'networks' => $networks, 'post' => $post, 'sent' => $sent, 'taken' => $taken];
            }
        }

        usort($projection, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return $projection;
    }

    // When each approved post goes out next, by post id - the first moment the projection gives it within $days days, a post no slot reaches meanwhile being absent
    /**
     * @param list<string> $connected
     *
     * @return array<int, \DateTimeImmutable>
     */
    public function nextMoments(array $connected, int $days = 60, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $moments = [];
        foreach ($this->project($now, $now->modify('+' . $days . ' days'), $connected, $now) as $occurrence) {
            if (null !== $occurrence['post']) {
                $moments[(int) $occurrence['post']->getId()] ??= $occurrence['at'];
            }
        }

        return $moments;
    }

    // The enabled slots' moments between the two, in time order
    /** @return list<array{0: \DateTimeImmutable, 1: SocialSchedule}> */
    private function occurrences(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $slots = $this->scheduleRepository->findEnabled();
        $occurrences = [];
        for ($day = $from->setTime(0, 0); $day < $to; $day = $day->modify('+1 day')) {
            foreach ($slots as $slot) {
                $time = $slot->getTime();
                if (null === $time) {
                    continue;
                }

                $at = $day->setTime((int) $time->format('G'), (int) $time->format('i'));
                if ($at >= $from && $at < $to) {
                    $occurrences[] = [$at, $slot];
                }
            }
        }

        usort($occurrences, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $occurrences;
    }

    /**
     * @param array<int, list<string>>|null $remaining
     *
     * @return list<string>
     */
    private function networksOf(SocialPost $post, ?array $remaining): array
    {
        return null === $remaining ? $post->getApprovedNetworks() : ($remaining[(int) $post->getId()] ?? []);
    }
}
