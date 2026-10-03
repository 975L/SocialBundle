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
use c975L\SocialBundle\Entity\SocialSchedule;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Repository\SocialScheduleRepository;
use c975L\SocialBundle\Service\SocialPlanner;
use PHPUnit\Framework\TestCase;

class SocialPlannerTest extends TestCase
{
    private int $nextId = 1;

    // An approved post on the given networks, its id set the way the database would
    /** @param list<string> $networks */
    private function post(array $networks = ['bluesky'], ?string $plannedAt = null): SocialPost
    {
        $post = new SocialPost('gallery_media', (string) $this->nextId, 'Title', 'https://example.org/', null);
        new \ReflectionProperty(SocialPost::class, 'id')->setValue($post, $this->nextId++);
        foreach ($networks as $network) {
            new SocialPostTarget($post, $network, 'Text');
        }
        $post->approve();

        return $post->setPlannedAt(null === $plannedAt ? null : new \DateTimeImmutable($plannedAt));
    }

    /**
     * @param list<SocialPost>     $posts
     * @param list<SocialSchedule> $slots
     */
    private function planner(array $posts = [], array $slots = []): SocialPlanner
    {
        $postRepository = $this->createStub(SocialPostRepository::class);
        $postRepository->method('findApproved')->willReturn($posts);
        $scheduleRepository = $this->createStub(SocialScheduleRepository::class);
        $scheduleRepository->method('findEnabled')->willReturn($slots);

        return new SocialPlanner($postRepository, $scheduleRepository);
    }

    /** @param list<string> $networks */
    private function slot(string $time, array $networks = []): SocialSchedule
    {
        return new SocialSchedule()->setTime(new \DateTimeImmutable($time))->setNetworks($networks);
    }

    // Unplanned posts go out in the order they were handed, the order prepared - a planned one never, its own moment sending it
    public function testASlotTakesTheFirstUnplannedPostInTheOrderPrepared(): void
    {
        $first = $this->post();

        $this->assertSame($first, $this->planner()->pick([$this->post(plannedAt: '2026-10-03 19:00'), $first, $this->post()], ['bluesky']));
    }

    // A slot sends nothing approved for another network than its own
    public function testASlotOnlyTakesWhatWaitsOnItsNetworks(): void
    {
        $this->assertNull($this->planner()->pick([$this->post(['instagram'])], ['bluesky']));
    }

    // A post planned on a slot's quarter of an hour, on one of its networks, stands in its place
    public function testAPlannedPostTakesTheSlotOfItsQuarterOfAnHour(): void
    {
        $at = new \DateTimeImmutable('2026-10-03 19:00');

        $this->assertTrue($this->planner()->isTaken([$this->post(plannedAt: '2026-10-03 19:10')], ['bluesky'], $at));
        $this->assertFalse($this->planner()->isTaken([$this->post(plannedAt: '2026-10-03 19:15')], ['bluesky'], $at));
        $this->assertFalse($this->planner()->isTaken([$this->post(['instagram'], '2026-10-03 19:00')], ['bluesky'], $at));
    }

    // A draft planned on a slot's quarter of an hour leaves it to the queue, one already sent by the planned run still taking it
    public function testOnlyAPostGoingOutTakesTheSlot(): void
    {
        $at = new \DateTimeImmutable('2026-10-03 19:00');
        $draft = new SocialPost('gallery_media', '99', 'Title', 'https://example.org/', null)->setPlannedAt($at);
        new SocialPostTarget($draft, 'bluesky', 'Text');
        $published = $this->post(plannedAt: '2026-10-03 19:00');
        $published->getTargets()->first()->markPublished('external');

        $this->assertFalse($this->planner()->isTaken([$draft], ['bluesky'], $at));
        $this->assertTrue($this->planner()->isTaken([$published], ['bluesky'], $at));
    }

    // A post planned in the past goes out with the next quarter of an hour the planned run reaches
    public function testTheNextQuarterOfAnHour(): void
    {
        $this->assertSame('2026-10-03 18:15', $this->planner()->nextQuarter(new \DateTimeImmutable('2026-10-03 18:07'))->format('Y-m-d H:i'));
        $this->assertSame('2026-10-03 18:15', $this->planner()->nextQuarter(new \DateTimeImmutable('2026-10-03 18:00'))->format('Y-m-d H:i'));
    }

    // Two slots a day: the queue fills them in order, the past ones of today left out, and an empty one says so
    public function testTheProjectionFillsTheSlotsInOrder(): void
    {
        [$a, $b] = [$this->post(), $this->post()];
        $projection = $this->planner([$a, $b], [$this->slot('19:00'), $this->slot('08:00')])
            ->project(new \DateTimeImmutable('2026-10-03'), new \DateTimeImmutable('2026-10-05'), ['bluesky'], new \DateTimeImmutable('2026-10-03 12:00'));

        $this->assertSame(['2026-10-03 19:00', '2026-10-04 08:00', '2026-10-04 19:00'], array_map(static fn (array $occurrence): string => $occurrence['at']->format('Y-m-d H:i'), $projection));
        $this->assertSame([$a, $b, null], array_column($projection, 'post'));
        $this->assertSame([['bluesky'], ['bluesky'], []], array_column($projection, 'sent'));
    }

    // A post on two networks served by two slots appears twice, once per network; the evening slot takes the rest of it before the next post
    public function testAPostIsSplitBetweenTheSlotsOfItsNetworks(): void
    {
        [$both, $next] = [$this->post(['bluesky', 'facebook']), $this->post(['bluesky', 'facebook'])];
        $projection = $this->planner([$both, $next], [$this->slot('08:00', ['bluesky']), $this->slot('19:00', ['facebook'])])
            ->project(new \DateTimeImmutable('2026-10-03'), new \DateTimeImmutable('2026-10-05'), ['bluesky', 'facebook'], new \DateTimeImmutable('2026-10-03 00:00'));

        $this->assertSame([$both, $both, $next, $next], array_column($projection, 'post'));
    }

    // A month ahead still starts from now: what the slots before it send is not there any more
    public function testAMonthAheadSkipsWhatTheSlotsBeforeItSend(): void
    {
        $projection = $this->planner([$this->post(), $this->post()], [$this->slot('19:00')])
            ->project(new \DateTimeImmutable('2026-10-04'), new \DateTimeImmutable('2026-10-06'), ['bluesky'], new \DateTimeImmutable('2026-10-03 12:00'));

        $this->assertSame([2, null], array_map(static fn (?SocialPost $post): ?int => $post?->getId(), array_column($projection, 'post')));
    }

    // A planned post goes out at its own moment, and on its slot's quarter of an hour takes the slot's place: the queue goes on the day after
    public function testAPlannedPostGoesOutAtItsMomentAndTakesItsSlot(): void
    {
        [$unplanned, $afternoon, $evening] = [$this->post(), $this->post(plannedAt: '2026-10-03 14:15'), $this->post(plannedAt: '2026-10-03 19:00')];
        $projection = $this->planner([$unplanned, $afternoon, $evening], [$this->slot('19:00')])
            ->project(new \DateTimeImmutable('2026-10-03'), new \DateTimeImmutable('2026-10-05'), ['bluesky'], new \DateTimeImmutable('2026-10-03 00:00'));

        $this->assertSame(['2026-10-03 14:15', '2026-10-03 19:00', '2026-10-03 19:00', '2026-10-04 19:00'], array_map(static fn (array $occurrence): string => $occurrence['at']->format('Y-m-d H:i'), $projection));
        $this->assertSame([$afternoon, $evening, null, $unplanned], array_column($projection, 'post'));
        $this->assertSame([false, false, true, false], array_column($projection, 'taken'));
    }

    // Planned in the past and not sent yet, a post shows at the next quarter of an hour, when the planned run sends it
    public function testAPlannedPostOverdueShowsAtTheNextQuarter(): void
    {
        $projection = $this->planner([$this->post(plannedAt: '2026-10-03 09:00')])
            ->project(new \DateTimeImmutable('2026-10-03'), new \DateTimeImmutable('2026-10-04'), ['bluesky'], new \DateTimeImmutable('2026-10-03 12:05'));

        $this->assertSame('2026-10-03 12:15', $projection[0]['at']->format('Y-m-d H:i'));
    }

    // Where each post of the queue stands: its first moment, a post no slot reaches meanwhile absent
    public function testEachApprovedPostKnowsWhenItGoesOutNext(): void
    {
        [$first, $second, $planned] = [$this->post(), $this->post(['instagram']), $this->post(plannedAt: '2026-10-05 14:15')];

        $moments = $this->planner([$first, $second, $planned], [$this->slot('19:00', ['bluesky'])])->nextMoments(['bluesky', 'instagram'], 3, new \DateTimeImmutable('2026-10-03 12:00'));

        $this->assertSame([1 => '2026-10-03 19:00', 3 => '2026-10-05 14:15'], array_map(static fn (\DateTimeImmutable $at): string => $at->format('Y-m-d H:i'), $moments));
    }
}
