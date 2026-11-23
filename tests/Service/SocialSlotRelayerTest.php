<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Entity\SocialSlot;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Repository\SocialSlotRepository;
use c975L\SocialBundle\Service\SocialSlotCalendar;
use c975L\SocialBundle\Service\SocialSlotFinder;
use c975L\SocialBundle\Service\SocialSlotRelayer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class SocialSlotRelayerTest extends TestCase
{
    // A post to come of the series, its id given
    private function post(int $id, string $at, bool $approved = false): SocialPost
    {
        $post = new SocialPost(SocialPost::SOURCE_MANUAL, 'item-' . $id, 'item-' . $id, '', null, new \DateTimeImmutable($at));
        $target = new SocialPostTarget($post, 'bluesky', 'item-' . $id);
        if ($approved) {
            $target->approve();
        }
        new \ReflectionProperty(SocialPost::class, 'id')->setValue($post, $id);

        return $post;
    }

    /** @param list<SocialPost> $posts */
    private function relayer(array $posts, array $taken = []): SocialSlotRelayer
    {
        $slots = $this->createStub(SocialSlotRepository::class);
        $slots->method('findBySeries')->willReturn([new SocialSlot()->setLabel('item-1')->setWeekday(5)->setTime('21:00')]);
        $repository = $this->createStub(SocialPostRepository::class);
        $repository->method('findComingBySeries')->willReturn($posts);
        $repository->method('findTakenAt')->willReturnCallback(static fn (array $moments): array => array_intersect_key($taken, array_flip(array_map(static fn (\DateTimeImmutable $moment): string => $moment->format('Y-m-d H:i'), $moments))));
        $calendar = new SocialSlotCalendar();

        return new SocialSlotRelayer($slots, $calendar, new SocialSlotFinder($calendar, $repository), $repository, $this->createStub(EntityManagerInterface::class));
    }

    // The drafts off the slot are counted, then laid on its next free Fridays in their order, an approved post left where it is and a minute held passed over
    public function testTheDraftsAreLaidAgainOnTheSlots(): void
    {
        // The first Friday whose slot is still to come, the relay starting from now
        $friday = new \DateTimeImmutable('today 21:00');
        while ('5' !== $friday->format('N') || $friday <= new \DateTimeImmutable()) {
            $friday = $friday->modify('+1 day');
        }
        $first = $this->post(1, $friday->modify('-1 day')->format('Y-m-d 20:30'));
        $second = $this->post(2, $friday->modify('+6 days')->format('Y-m-d 20:30'));
        $approved = $this->post(3, $friday->modify('+1 day')->format('Y-m-d 20:30'), true);
        $taken = [$friday->format('Y-m-d H:i') => 'item-4'];
        $relayer = $this->relayer([$first, $approved, $second], $taken);
        $series = new SocialSeries('item-1', 1, 'days', 1, [], 'text', 'Text', [], ['bluesky']);

        $this->assertSame(2, $relayer->countOffSlot($series));
        $this->assertSame(2, $relayer->relay($series));
        $this->assertSame([$friday->modify('+7 days')->format('Y-m-d H:i'), $friday->modify('+14 days')->format('Y-m-d H:i')], [$first->getPlannedAt()->format('Y-m-d H:i'), $second->getPlannedAt()->format('Y-m-d H:i')]);
        $this->assertSame($friday->modify('+1 day')->format('Y-m-d') . ' 20:30', $approved->getPlannedAt()->format('Y-m-d H:i'));
    }
}
