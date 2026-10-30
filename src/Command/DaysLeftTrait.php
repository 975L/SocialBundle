<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Command;

// The days left before an end, for the reminders mailed on fixed days
trait DaysLeftTrait
{
    // Counted in calendar days of the server's timezone, whatever offset the end was stored with, so the nightly run meets each of them once
    private function daysLeft(?\DateTimeImmutable $end): ?int
    {
        $today = new \DateTimeImmutable('today');

        return null === $end ? null : (int) $today->diff($end->setTimezone($today->getTimezone())->setTime(0, 0))->format('%r%a');
    }
}
