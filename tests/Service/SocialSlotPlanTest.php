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
use c975L\SocialBundle\Service\SocialMediaChecker;
use c975L\SocialBundle\Service\SocialPublisher;
use c975L\SocialBundle\Service\SocialSeriesGenerator;
use c975L\SocialBundle\Service\SocialSlotCalendar;
use c975L\SocialBundle\Service\SocialSlotPlan;
use c975L\UiBundle\Model\SocialContent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

class SocialSlotPlanTest extends TestCase
{
    // A post at a moment, on one network
    private function post(int $id, string $at, ?SocialSeries $series = null): SocialPost
    {
        $post = new SocialPost(SocialPost::SOURCE_MANUAL, 'item-' . $id, 'item-' . $id, '', null, new \DateTimeImmutable($at));
        new SocialPostTarget($post, 'bluesky', 'item-' . $id);
        $post->setSeries($series);
        new \ReflectionProperty(SocialPost::class, 'id')->setValue($post, $id);

        return $post;
    }

    // A series drawing from the contents, its id given
    private function series(int $id): SocialSeries
    {
        $series = new SocialSeries('catalog', 1, 'weekdays', 1, [1], SocialSeriesGenerator::MODE_SOURCE, '', ['book'], ['bluesky']);
        new \ReflectionProperty(SocialSeries::class, 'id')->setValue($series, $id);

        return $series;
    }

    /**
     * @param list<SocialSlot>                                                    $slots
     * @param list<SocialPost>                                                    $posts
     * @param array<string, list<TranslatableMessage>>                            $problems
     * @param list<array{source: string, scope: ?string, content: SocialContent}> $free
     */
    private function plan(array $slots, array $posts, array $problems = [], array $free = [], bool $countable = true): SocialSlotPlan
    {
        $slotRepository = $this->createStub(SocialSlotRepository::class);
        $slotRepository->method('findOrdered')->willReturn($slots);
        $postRepository = $this->createStub(SocialPostRepository::class);
        $postRepository->method('findPlannedBetween')->willReturn($posts);
        $checker = $this->createStub(SocialMediaChecker::class);
        $checker->method('check')->willReturn($problems);
        $publisher = $this->createStub(SocialPublisher::class);
        $publisher->method('freeContentsOf')->willReturn($free);
        $publisher->method('countsFreeContents')->willReturn($countable);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new SocialSlotPlan($slotRepository, $postRepository, new SocialSlotCalendar(), $checker, $publisher, $translator, new MockClock('2026-11-30 12:00'));
    }

    // Each moment of the slots comes with its post or left empty, a post off the slots among them, with the posts of its day
    public function testTheMomentsComeWithTheirPostOrEmpty(): void
    {
        $monday = new SocialSlot()->setLabel('item-1')->setWeekday(1)->setTime('18:00');
        $plan = $this->plan([$monday], [$this->post(1, '2026-11-30 18:00'), $this->post(2, '2026-12-03 09:00')])->read(new \DateTimeImmutable('2026-12-07'));

        $this->assertSame(['2026-11-30 18:00', '2026-12-03 09:00', '2026-12-07 18:00'], array_column($plan['moments'], 'at'));
        $this->assertSame('item-1', $plan['moments'][0]['slot']['label']);
        $this->assertSame('draft', $plan['moments'][0]['post']['state']);
        $this->assertSame(['bluesky'], $plan['moments'][0]['post']['networks']);
        $this->assertNull($plan['moments'][1]['slot']);
        $this->assertNull($plan['moments'][2]['post']);
        $this->assertSame([1, 1, 0], array_column($plan['moments'], 'dayCount'));
        $this->assertSame('2026-12-07', $plan['until']);
    }

    // What a network would refuse in a post is said, the network first
    public function testAPostSaysWhatANetworkWouldRefuse(): void
    {
        $plan = $this->plan([], [$this->post(1, '2026-12-01 09:00')], ['instagram' => [new TranslatableMessage('A picture is required.')]])->read(new \DateTimeImmutable('2026-12-02'));

        $this->assertSame(['instagram: A picture is required.'], $plan['moments'][0]['post']['problems']);
    }

    // A series drawing from the contents runs out when it has more slots left empty than contents free
    public function testASeriesOnSlotsRunsOutOfContents(): void
    {
        $series = $this->series(4);
        $monday = new SocialSlot()->setLabel('item-1')->setWeekday(1)->setTime('18:00')->setSeries($series);
        $free = [['source' => 'book', 'scope' => null, 'content' => new SocialContent('1', 'item-2', 'https://example.org/item-2')]];

        $plan = $this->plan([$monday], [$this->post(1, '2026-11-30 18:00', $series)], free: $free)->read(new \DateTimeImmutable('2026-12-14'));

        $this->assertSame([['id' => 4, 'title' => 'catalog', 'toFill' => 2, 'free' => 1, 'runsOut' => true]], $plan['series']);
        $this->assertSame(4, $plan['moments'][1]['series']['id']);
    }

    // A series whose source hands its contents out one by one or by date has its contents left unknown, never said to run out
    public function testASeriesWhoseContentsCannotBeCountedNeverRunsOut(): void
    {
        $series = $this->series(4);
        $monday = new SocialSlot()->setLabel('item-1')->setWeekday(1)->setTime('18:00')->setSeries($series);

        $plan = $this->plan([$monday], [], countable: false)->read(new \DateTimeImmutable('2026-12-14'));

        $this->assertSame([['id' => 4, 'title' => 'catalog', 'toFill' => 3, 'free' => null, 'runsOut' => false]], $plan['series']);
    }

    // A slot without a series, or an imported series, is no series to fill from the contents
    public function testOnlySeriesDrawingFromTheContentsAreCounted(): void
    {
        $plan = $this->plan([new SocialSlot()->setLabel('item-1')->setWeekday(1)->setTime('18:00')], [])->read(new \DateTimeImmutable('2026-12-14'));

        $this->assertSame([], $plan['series']);
        $this->assertCount(3, $plan['moments']);
    }
}
