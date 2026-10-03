<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Scheduler;

use c975L\SocialBundle\Entity\SocialSchedule;
use c975L\SocialBundle\Repository\SocialScheduleRepository;
use c975L\SocialBundle\Scheduler\SocialMaintenanceTaskProvider;
use PHPUnit\Framework\TestCase;

class SocialMaintenanceTaskProviderTest extends TestCase
{
    /** @param list<SocialSchedule> $slots */
    private function createProvider(array $slots = [], ?\Throwable $failure = null): SocialMaintenanceTaskProvider
    {
        $repository = $this->createStub(SocialScheduleRepository::class);
        null === $failure ? $repository->method('findEnabled')->willReturn($slots) : $repository->method('findEnabled')->willThrowException($failure);

        return new SocialMaintenanceTaskProvider($repository);
    }

    private function createSlot(int $id, string $time): SocialSchedule
    {
        $slot = new SocialSchedule()->setName('Slot ' . $id)->setTime(new \DateTimeImmutable($time));
        new \ReflectionProperty(SocialSchedule::class, 'id')->setValue($slot, $id);

        return $slot;
    }

    public function testTheReviewsSyncIsScheduledNightly(): void
    {
        $tasks = $this->createProvider()->getMaintenanceTasks();

        $this->assertSame('c975l:social:reviews:sync', $tasks[0]->command);
        $this->assertSame('# #(2-5) * * *', $tasks[0]->expression);
    }

    // Hourly, the interval being the command's to check: a site changing it needs no new schedule
    public function testThePublicationIsCheckedHourly(): void
    {
        $tasks = $this->createProvider()->getMaintenanceTasks();

        $this->assertCount(3, $tasks);
        $this->assertSame('c975l:social:publish', $tasks[1]->command);
        $this->assertSame('# * * * *', $tasks[1]->expression);
        // The planned posts, to the quarter of an hour the calendar plans them by
        $this->assertSame(['*/15 * * * *', 'c975l:social:publish --planned'], [$tasks[2]->expression, $tasks[2]->command]);
    }

    // One task of its own per slot, at its time - two slots at the same time being two tasks all the same
    public function testEachSlotIsATaskAtItsOwnTime(): void
    {
        $tasks = $this->createProvider([$this->createSlot(1, '08:05'), $this->createSlot(2, '08:05'), $this->createSlot(3, '19:30')])->getMaintenanceTasks();

        $this->assertCount(6, $tasks);
        $this->assertSame(['5 8 * * *', 'c975l:social:publish --slot=1'], [$tasks[3]->expression, $tasks[3]->command]);
        $this->assertSame(['5 8 * * *', 'c975l:social:publish --slot=2'], [$tasks[4]->expression, $tasks[4]->command]);
        $this->assertSame(['30 19 * * *', 'c975l:social:publish --slot=3'], [$tasks[5]->expression, $tasks[5]->command]);
    }

    // Before the migration created the table, every other task still has to run
    public function testAnUnreadableTableSchedulesNoSlot(): void
    {
        $this->assertCount(3, $this->createProvider(failure: new \RuntimeException('Table not found'))->getMaintenanceTasks());
    }
}
