<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Service\SocialPlanner;
use PHPUnit\Framework\TestCase;

class SocialPlannerTest extends TestCase
{
    // A moment as the planner returns it, to the second
    private function at(\DateTimeImmutable $moment): string
    {
        return $moment->format('Y-m-d H:i:s');
    }

    // A post planned in the past goes out with the next quarter of an hour the planned run reaches
    public function testTheNextQuarterOfAnHour(): void
    {
        $planner = new SocialPlanner();

        $this->assertSame('2026-10-03 18:15:00', $this->at($planner->nextQuarter(new \DateTimeImmutable('2026-10-03 18:07'))));
        $this->assertSame('2026-10-03 18:15:00', $this->at($planner->nextQuarter(new \DateTimeImmutable('2026-10-03 18:00'))));
        $this->assertSame('2026-10-04 00:00:00', $this->at($planner->nextQuarter(new \DateTimeImmutable('2026-10-03 23:59:59'))));
    }

    // A moment goes to the nearest quarter of an hour, the half way up, its seconds dropped
    public function testAMomentIsRoundedToTheNearestQuarter(): void
    {
        $planner = new SocialPlanner();

        $this->assertSame('2026-10-03 18:00:00', $this->at($planner->round(new \DateTimeImmutable('2026-10-03 18:07:29'))));
        $this->assertSame('2026-10-03 18:15:00', $this->at($planner->round(new \DateTimeImmutable('2026-10-03 18:07:30'))));
        $this->assertSame('2026-10-03 18:15:00', $this->at($planner->round(new \DateTimeImmutable('2026-10-03 18:15'))));
        $this->assertSame('2026-10-04 00:00:00', $this->at($planner->round(new \DateTimeImmutable('2026-10-03 23:53'))));
    }

    // Rounding keeps the moment's time zone, the calendar showing it as planned
    public function testRoundingKeepsTheTimeZone(): void
    {
        $rounded = new SocialPlanner()->round(new \DateTimeImmutable('2026-10-03 18:08', new \DateTimeZone('Europe/Paris')));

        $this->assertSame('Europe/Paris', $rounded->getTimezone()->getName());
        $this->assertSame('2026-10-03 18:15:00', $this->at($rounded));
    }
}
