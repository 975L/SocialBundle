<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Command;

use c975L\SocialBundle\Command\SeriesProlongCommand;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Repository\SocialSeriesRepository;
use c975L\SocialBundle\Service\SocialSeriesGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class SeriesProlongCommandTest extends TestCase
{
    /** @var array<int, \DateTimeImmutable> */
    private array $last = [];

    /** @var list<int> */
    private array $prolonged = [];

    // Each prolongation adds a week to its series' last post, as a weekly series of one post would
    /** @param list<array{series: SocialSeries, lastPlannedAt: \DateTimeImmutable}> $rows */
    private function tester(array $rows): CommandTester
    {
        foreach ($rows as $row) {
            $this->last[(int) $row['series']->getId()] = $row['lastPlannedAt'];
        }
        $repository = $this->createStub(SocialSeriesRepository::class);
        $repository->method('findWithLastPlannedAt')->willReturn($rows);
        $repository->method('findLastPlannedAt')->willReturnCallback(fn (SocialSeries $series): \DateTimeImmutable => $this->last[(int) $series->getId()]);
        $generator = $this->createStub(SocialSeriesGenerator::class);
        $generator->method('prolong')->willReturnCallback(function (SocialSeries $series, ?\DateTimeImmutable $until): array {
            $next = $this->last[(int) $series->getId()]->modify('+7 days');
            if ($next > $until) {
                return [];
            }
            $this->last[(int) $series->getId()] = $next;
            $this->prolonged[] = (int) $series->getId();

            return [new SocialPost(SocialPost::SOURCE_MANUAL, 'id', '', '', null, $next)];
        });
        $generator->method('prolongMoments')->willReturnCallback(static function (SocialSeries $series, \DateTimeImmutable $last, ?\DateTimeImmutable $until): array {
            $next = $last->modify('+7 days');

            return $next > $until ? [] : [$next];
        });

        return new CommandTester(new SeriesProlongCommand($repository, $generator));
    }

    private function series(int $id, string $mode = SocialSeriesGenerator::MODE_TEXT): SocialSeries
    {
        $series = new SocialSeries('Series ' . $id, 1, 'days', 7, [], $mode, 'Text', [], ['bluesky']);
        new \ReflectionProperty(SocialSeries::class, 'id')->setValue($series, $id);

        return $series;
    }

    // A running series is prolonged until the day, an ended or imported one left as it is, and a second run makes nothing more
    public function testTheRunningSeriesReachTheDayOnce(): void
    {
        $rows = [
            ['series' => $this->series(1), 'lastPlannedAt' => new \DateTimeImmutable('+3 days 21:00')],
            ['series' => $this->series(2), 'lastPlannedAt' => new \DateTimeImmutable('-3 days 21:00')],
            ['series' => $this->series(3, SocialSeriesGenerator::MODE_IMPORT), 'lastPlannedAt' => new \DateTimeImmutable('+3 days 21:00')],
        ];
        $until = new \DateTimeImmutable('+30 days')->format('Y-m-d');

        $this->assertSame(Command::SUCCESS, $this->tester($rows)->execute(['--until' => $until]));
        $this->assertSame([1, 1, 1], $this->prolonged, '+3 days to +30 days: three more weeks');

        $this->prolonged = [];
        $rows[0]['lastPlannedAt'] = $this->last[1];
        $this->tester($rows)->execute(['--until' => $until]);
        $this->assertSame([], $this->prolonged);
    }

    // A series named is prolonged even once ended, and a day not written YYYY-MM-DD is refused
    public function testANamedSeriesAndAWrongDay(): void
    {
        $rows = [['series' => $this->series(2), 'lastPlannedAt' => new \DateTimeImmutable('-3 days 21:00')]];

        $this->assertSame(Command::FAILURE, $this->tester($rows)->execute(['--until' => 'december']));
        $this->tester($rows)->execute(['series' => ['2'], '--until' => new \DateTimeImmutable('+10 days')->format('Y-m-d')]);
        $this->assertNotSame([], $this->prolonged);
    }

    // A dry run counts the drafts to come and where the series would end, writing nothing, and says which series named were left out
    public function testADryRunCountsAndTheSeriesLeftOutAreSaid(): void
    {
        $rows = [
            ['series' => $this->series(1), 'lastPlannedAt' => new \DateTimeImmutable('+3 days 21:00')],
            ['series' => $this->series(3, SocialSeriesGenerator::MODE_IMPORT), 'lastPlannedAt' => new \DateTimeImmutable('+3 days 21:00')],
        ];
        $tester = $this->tester($rows);

        $this->assertSame(Command::SUCCESS, $tester->execute(['series' => ['1', '3', '42'], '--until' => new \DateTimeImmutable('+30 days')->format('Y-m-d'), '--dry-run' => true]));
        $this->assertSame([], $this->prolonged);
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        $this->assertStringContainsString('Series 3, 42 not found or imported, left out.', $display);
        $this->assertMatchesRegularExpression('/Series 1 \S+ \S+ 3 ' . preg_quote(new \DateTimeImmutable('+24 days 21:00')->format('d/m/Y H:i'), '/') . '/', $display);
    }

    // A day the rounds cannot reach is said, the command to be run again
    public function testRoundsRunningOutAreSaid(): void
    {
        $tester = $this->tester([['series' => $this->series(1), 'lastPlannedAt' => new \DateTimeImmutable('+3 days 21:00')]]);

        $tester->execute(['--until' => new \DateTimeImmutable('+1000 days')->format('Y-m-d'), '--dry-run' => true]);
        $this->assertStringContainsString('short of --until: run the command again', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }
}
