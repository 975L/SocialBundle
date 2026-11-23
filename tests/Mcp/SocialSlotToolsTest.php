<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Mcp;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Entity\SocialSlot;
use c975L\SocialBundle\Mcp\SocialMcpAccess;
use c975L\SocialBundle\Mcp\SocialSlotTools;
use c975L\SocialBundle\Repository\SocialSeriesRepository;
use c975L\SocialBundle\Repository\SocialSlotRepository;
use c975L\SocialBundle\Service\SocialSeriesGenerator;
use c975L\SocialBundle\Service\SocialSlotCalendar;
use c975L\SocialBundle\Service\SocialSlotPlan;
use c975L\SocialBundle\Service\SocialSlotRelayer;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Server\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class SocialSlotToolsTest extends TestCase
{
    // The week read by day then time, each slot with the one it meets at the same minute and its next moments
    public function testList(): void
    {
        $friday = $this->slot(1, 5, '21:00');
        $other = $this->slot(2, 5, '21:00');
        $slots = $this->createStub(SocialSlotRepository::class);
        $slots->method('findOrdered')->willReturn([$friday, $other]);

        $week = $this->tools($slots)->list();

        $this->assertSame([[2], [1]], array_column($week, 'meets'));
        $this->assertSame(['2026-11-27 21:00', '2026-12-04 21:00', '2026-12-11 21:00'], $week[0]['next']);
        $this->assertSame(['item-1', 'week', 5, '21:00'], [$week[0]['label'], $week[0]['pace'], $week[0]['weekday'], $week[0]['time']]);
    }

    // A slot written with its series and its key, a date of the month leaving the weekday out
    public function testSaveAddsASlot(): void
    {
        $persisted = [];
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $series = $this->createStub(SocialSeriesRepository::class);
        $series->method('find')->willReturn($this->series('source'));

        $saved = $this->tools(series: $series, entityManager: $entityManager)->save(' item-1 ', SocialSlot::MONTH_DAY, '09:15', 3, monthDay: 31, key: 'item-1', series: 4);

        $this->assertInstanceOf(SocialSlot::class, $persisted[0]);
        $this->assertSame(['item-1', null, 31, 'item-1', 'catalog'], [$saved['label'], $saved['weekday'], $saved['month_day'], $saved['key'], $saved['series']['title']]);
    }

    // A series imported from a file is refused: its moments are its file's
    public function testSaveRefusesAnImportedSeries(): void
    {
        $series = $this->createStub(SocialSeriesRepository::class);
        $series->method('find')->willReturn($this->series(SocialSeriesGenerator::MODE_IMPORT));

        $this->expectException(ToolCallException::class);
        $this->tools(series: $series)->save('item-1', SocialSlot::WEEK, '09:00', 1, series: 4);
    }

    // What the entity's constraints refuse is named and nothing is written
    public function testSaveRefusesWhatTheConstraintsRefuse(): void
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList([new ConstraintViolation('label.social_slot_missing', null, [], null, 'weekday', null)]));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('flush');

        $this->expectExceptionMessage('weekday: label.social_slot_missing');
        $this->tools(entityManager: $entityManager, validator: $validator)->save('item-1', SocialSlot::WEEK, '09:00');
    }

    // A rewrite the constraints refuse is taken back off the managed slot, nothing written
    public function testARefusedRewriteIsTakenBack(): void
    {
        $slots = $this->createStub(SocialSlotRepository::class);
        $slots->method('find')->willReturn($this->slot(1, 5, '21:00'));
        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList([new ConstraintViolation('label.social_slot_missing', null, [], null, 'rank', null)]));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('refresh');
        $entityManager->expects($this->never())->method('flush');

        $this->expectException(ToolCallException::class);
        $this->tools($slots, entityManager: $entityManager, validator: $validator)->save('item-1', SocialSlot::MONTH_RANK, '09:00', 5, slot: 1);
    }

    // A series' drafts laid again on its slots, how many moved given back
    public function testRelay(): void
    {
        $series = $this->createStub(SocialSeriesRepository::class);
        $series->method('find')->willReturn($this->series('source'));
        $relayer = $this->createStub(SocialSlotRelayer::class);
        $relayer->method('relay')->willReturn(2);

        $this->assertSame(['series' => 4, 'moved' => 2], $this->tools(series: $series, relayer: $relayer)->relay(4));
    }

    // A series imported from a file is not laid again: its moments are its file's
    public function testRelayRefusesAnImportedSeries(): void
    {
        $series = $this->createStub(SocialSeriesRepository::class);
        $series->method('find')->willReturn($this->series(SocialSeriesGenerator::MODE_IMPORT));

        $this->expectException(ToolCallException::class);
        $this->tools(series: $series)->relay(4);
    }

    // The plan of the coming days, read up to the day asked
    public function testPlan(): void
    {
        $plan = $this->createStub(SocialSlotPlan::class);
        $plan->method('read')->willReturnCallback(static fn (\DateTimeImmutable $until): array => ['until' => $until->format('Y-m-d'), 'moments' => [], 'series' => []]);

        $this->assertSame(['until' => '2027-01-05', 'moments' => [], 'series' => []], $this->tools(plan: $plan)->plan('2027-01-05'));
    }

    // A day written otherwise, rolled over, missing, or past the 92 days an agent reads at most, is refused
    #[DataProvider('badPlanDayProvider')]
    public function testPlanRefusesABadDay(string $until): void
    {
        $this->expectException(ToolCallException::class);
        $this->tools()->plan($until);
    }

    /** @return iterable<string, array{string}> */
    public static function badPlanDayProvider(): iterable
    {
        yield 'written otherwise' => ['05/01/2027'];
        yield 'rolled over' => ['2027-02-30'];
        yield 'none' => [''];
        yield 'too far' => ['2027-06-01'];
    }

    // A slot asked by an id it does not have is refused
    public function testDeleteRefusesAnUnknownSlot(): void
    {
        $this->expectException(ToolCallException::class);
        $this->tools()->delete(9);
    }

    // An account that is no editor reaches neither the week nor its writing
    public function testAnAccountNoEditorIsRefused(): void
    {
        $this->expectException(ToolCallException::class);
        $this->tools(granted: false)->list();
    }

    // A slot of the week, its id given
    private function slot(int $id, int $weekday, string $time): SocialSlot
    {
        $slot = new SocialSlot()->setLabel('item-' . $id)->setWeekday($weekday)->setTime($time);
        new \ReflectionProperty(SocialSlot::class, 'id')->setValue($slot, $id);

        return $slot;
    }

    // A series in the mode given
    private function series(string $mode): SocialSeries
    {
        $series = $this->createStub(SocialSeries::class);
        $series->method('getMode')->willReturn($mode);
        $series->method('getTitle')->willReturn('catalog');
        $series->method('getId')->willReturn(4);

        return $series;
    }

    // The tools on stubs, the account an editor unless said otherwise
    private function tools(?SocialSlotRepository $slots = null, ?SocialSeriesRepository $series = null, ?EntityManagerInterface $entityManager = null, ?ValidatorInterface $validator = null, bool $granted = true, ?SocialSlotRelayer $relayer = null, ?SocialSlotPlan $plan = null): SocialSlotTools
    {
        $config = $this->createStub(ConfigServiceInterface::class);
        $config->method('get')->willReturn('ROLE_EDITOR');
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn($granted);
        if (null === $validator) {
            $validator = $this->createStub(ValidatorInterface::class);
            $validator->method('validate')->willReturn(new ConstraintViolationList());
        }

        return new SocialSlotTools($slots ?? $this->createStub(SocialSlotRepository::class), $series ?? $this->createStub(SocialSeriesRepository::class), new SocialSlotCalendar(), $relayer ?? $this->createStub(SocialSlotRelayer::class), $entityManager ?? $this->createStub(EntityManagerInterface::class), $validator, new SocialMcpAccess($config, $security), new MockClock('2026-11-21 10:00'), $plan ?? $this->createStub(SocialSlotPlan::class));
    }
}
