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
use c975L\SocialBundle\Repository\SocialPostRepository;

// The free moments of slots of the template week, or of any moments: those no other post holds, the ones held passed over and said with the post holding them - what a prolongation and the re-laying of drafts both plan on
class SocialSlotFinder
{
    // How many times the slots are read again past minutes already held, before giving up on finding the count
    private const int ROUNDS = 10;

    public function __construct(
        private readonly SocialSlotCalendar $slotCalendar,
        private readonly SocialPostRepository $postRepository,
    ) {
    }

    // The next $count free moments of the slots after $after, none past $until, the posts of $exceptIds - the ones being moved - holding nothing; and the minutes passed over with the title of the post holding them
    /**
     * @param list<SocialSlot> $slots
     * @param list<int>        $exceptIds
     *
     * @return array{list<\DateTimeImmutable>, array<string, string>}
     */
    public function free(array $slots, \DateTimeImmutable $after, int $count, ?\DateTimeImmutable $until = null, array $exceptIds = []): array
    {
        $free = [];
        $taken = [];
        $cursor = $after;
        for ($round = 0; \count($free) < $count && $round < self::ROUNDS; ++$round) {
            $moments = $this->slotCalendar->occurrences($slots, $cursor, $count - \count($free), $until);
            if ([] === $moments) {
                break;
            }
            [$unheld, $held] = $this->split($moments, $exceptIds);
            $free = [...$free, ...$unheld];
            $taken += $held;
            $cursor = end($moments);
        }

        return [$free, $taken];
    }

    // The moments no other post holds, and the minutes held with the title of the post holding them - the posts of $exceptIds holding nothing
    /**
     * @param list<\DateTimeImmutable> $moments
     * @param list<int>                $exceptIds
     *
     * @return array{list<\DateTimeImmutable>, array<string, string>}
     */
    public function split(array $moments, array $exceptIds = []): array
    {
        $held = $this->postRepository->findTakenAt($moments, $exceptIds);
        $free = array_values(array_filter($moments, static fn (\DateTimeImmutable $moment): bool => !isset($held[$moment->format('Y-m-d H:i')])));

        return [$free, $held];
    }
}
