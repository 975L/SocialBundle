<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Repository\SocialSeriesRepository;

// A series prolonged until a day, round after round as "Prolong the series" does (SocialSeriesGenerator::prolong()) - what c975l:social:series:prolong and the MCP endpoint share. The drafts wait on the calendar to be read and approved
class SocialSeriesProlonger
{
    // A guard against a series whose prolongation would never reach the day
    public const int MAX_ROUNDS = 100;

    public function __construct(
        private readonly SocialSeriesRepository $seriesRepository,
        private readonly SocialSeriesGenerator $seriesGenerator,
    ) {
    }

    // Prolonged from $last until its last post reaches $until, none made past it - on a dry run, its moments only counted. The number of drafts, the new end, whether the rounds ran out before the day, and the minutes passed over with the post holding them returned
    /** @return array{int, \DateTimeImmutable, bool, array<string, string>} */
    public function until(SocialSeries $series, \DateTimeImmutable $last, \DateTimeImmutable $until, bool $dryRun = false): array
    {
        $made = 0;
        $skipped = [];
        for ($round = 0; $last < $until && $round < self::MAX_ROUNDS; ++$round) {
            $skipped += $this->seriesGenerator->skippedMoments($series, $last, $until);
            if ($dryRun) {
                $new = $this->seriesGenerator->prolongMoments($series, $last, $until);
                $next = [] === $new ? null : end($new);
            } else {
                $new = $this->seriesGenerator->prolong($series, $until);
                $next = [] === $new ? null : $this->seriesRepository->findLastPlannedAt($series) ?? $until;
            }
            if (null === $next) {
                break;
            }
            $made += \count($new);
            $last = $next;
        }

        return [$made, $last, self::MAX_ROUNDS === $round && $last < $until, $skipped];
    }
}
