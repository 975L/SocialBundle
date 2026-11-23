<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Command;

use c975L\SocialBundle\Command\PlanCommand;
use c975L\SocialBundle\Service\SocialSlotPlan;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class PlanCommandTest extends TestCase
{
    // A plan of one moment left empty and one post with a problem, a series running out
    private const array PLAN = [
        'until' => '2026-12-07',
        'moments' => [
            ['at' => '2026-12-01 18:00', 'slot' => ['id' => 1, 'label' => 'item-1', 'key' => null], 'series' => null, 'post' => null, 'dayCount' => 0],
            ['at' => '2026-12-02 18:00', 'slot' => null, 'series' => null, 'post' => ['id' => 3, 'title' => 'item-2', 'state' => 'draft', 'networks' => ['bluesky'], 'problems' => ['instagram: no picture']], 'dayCount' => 1],
        ],
        'series' => [['id' => 4, 'title' => 'catalog', 'toFill' => 2, 'free' => 1, 'runsOut' => true]],
    ];

    private function tester(): CommandTester
    {
        $plan = $this->createStub(SocialSlotPlan::class);
        $plan->method('read')->willReturn(self::PLAN);

        return new CommandTester(new PlanCommand($plan));
    }

    // As JSON, the plan as the service reads it
    public function testThePlanAsJson(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['--until' => '2026-12-07', '--json' => true]));
        $this->assertSame(self::PLAN, json_decode($tester->getDisplay(), true));
    }

    // As a table, an empty moment said, a problem shown and a series running out warned
    public function testThePlanAsATable(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['--until' => '2026-12-07']));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('(empty)', $display);
        $this->assertStringContainsString('instagram: no picture', $display);
        $this->assertStringContainsString('catalog: 2 slot(s) to fill, 1 content(s) free', $display);
    }

    // A day written otherwise is refused
    public function testABadDayIsRefused(): void
    {
        $this->assertSame(Command::FAILURE, $this->tester()->execute(['--until' => '07/12/2026']));
    }
}
