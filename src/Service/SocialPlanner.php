<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

// The clock posts go out on: every post holds a moment, sent to the quarter of an hour by the planned run once approved - the calendar's grid being that fine
class SocialPlanner
{
    // The step the planned run goes by, and the calendar's grid
    public const int QUARTER = 900;

    // The first quarter of an hour the planned run reaches after $now, where a post planned in the past goes out
    public function nextQuarter(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->setTimestamp((intdiv($now->getTimestamp(), self::QUARTER) + 1) * self::QUARTER);
    }

    // A moment brought to the nearest quarter of an hour, the one the planned run sends it at
    public function round(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->setTimestamp((int) round($moment->getTimestamp() / self::QUARTER) * self::QUARTER);
    }
}
