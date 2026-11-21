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
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Repository\SocialSlotRepository;
use Doctrine\ORM\EntityManagerInterface;

// A series' drafts to come laid again on its slots once a slot moved: in their order, on the next free moments of its slots - a post approved, refused or gone out never moved, and holding its minute
class SocialSlotRelayer
{
    public function __construct(
        private readonly SocialSlotRepository $slotRepository,
        private readonly SocialSlotCalendar $slotCalendar,
        private readonly SocialSlotFinder $slotFinder,
        private readonly SocialPostRepository $postRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    // How many drafts to come of the series stand off its slots - none for a series without slots
    public function countOffSlot(SocialSeries $series): int
    {
        $slots = $this->slotRepository->findBySeries($series);
        if ([] === $slots) {
            return 0;
        }

        return \count(array_filter($this->drafts($series), fn (SocialPost $post): bool => null === $this->slotCalendar->slotAt($slots, $post->getPlannedAt())));
    }

    // The series' drafts to come laid again on the free moments of its slots, in their order - how many moved returned, a draft left where it is when its slots have no moment left for it
    public function relay(SocialSeries $series): int
    {
        $slots = $this->slotRepository->findBySeries($series);
        $drafts = [] === $slots ? [] : $this->drafts($series);
        if ([] === $drafts) {
            return 0;
        }

        [$moments] = $this->slotFinder->free($slots, new \DateTimeImmutable(), \count($drafts), null, array_map(static fn (SocialPost $post): int => (int) $post->getId(), $drafts));
        $moved = 0;
        foreach ($drafts as $index => $post) {
            $moment = $moments[$index] ?? null;
            if (null !== $moment && $moment->format('Y-m-d H:i') !== $post->getPlannedAt()->format('Y-m-d H:i')) {
                $post->setPlannedAt($moment);
                ++$moved;
            }
        }
        $this->entityManager->flush();

        return $moved;
    }

    // The series' posts to come still drafts everywhere, in their order
    /** @return list<SocialPost> */
    private function drafts(SocialSeries $series): array
    {
        return array_values(array_filter($this->postRepository->findComingBySeries($series, new \DateTimeImmutable()), static fn (SocialPost $post): bool => 'draft' === $post->getState()));
    }
}
