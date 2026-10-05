<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Command;

use c975L\SocialBundle\Command\PublishCommand;
use c975L\SocialBundle\Service\SocialPlanner;
use c975L\SocialBundle\Service\SocialPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class PublishCommandTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $called = [];

    /**
     * @param array<string, array<string, mixed>> $report
     */
    private function createTester(array $report, ?\Throwable $failure = null): CommandTester
    {
        $socialPublisher = $this->createStub(SocialPublisher::class);
        $socialPublisher->method('publishPlanned')->willReturnCallback(function (bool $dryRun) use ($report): array {
            $this->called = ['method' => 'publishPlanned', 'dryRun' => $dryRun];

            return $report;
        });
        $socialPublisher->method('prepareUrl')->willReturnCallback(function (string $url, \DateTimeImmutable $at, bool $dryRun) use ($report, $failure): array {
            $this->called = ['method' => 'prepareUrl', 'url' => $url, 'at' => $at, 'dryRun' => $dryRun];
            if (null !== $failure) {
                throw $failure;
            }

            return $report;
        });

        return new CommandTester(new PublishCommand($socialPublisher, new SocialPlanner()));
    }

    // Most quarter-hourly runs have nothing due, which a cron must not read as a failure
    public function testNothingToSendIsASuccess(): void
    {
        $tester = $this->createTester([]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Nothing to send.', $tester->getDisplay());
    }

    // A refused post is kept, failed, on the screen - the run itself did its job
    public function testEachNetworkIsReportedAndARefusalIsNoFailure(): void
    {
        $tester = $this->createTester(['bluesky' => ['status' => 'failed', 'message' => 'Down'], 'other' => ['status' => 'draft', 'message' => '']]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('bluesky: failed Down', $tester->getDisplay());
        $this->assertStringContainsString('other: draft', $tester->getDisplay());
    }

    // Without "--url", the run sends the planned posts whose moment has come
    public function testTheDefaultRunPublishesThePlannedPosts(): void
    {
        $this->createTester([])->execute([]);

        $this->assertSame(['method' => 'publishPlanned', 'dryRun' => false], $this->called);
    }

    // "--dry-run" is passed through to the planned run
    public function testTheDryRunIsPassedThrough(): void
    {
        $this->createTester([])->execute(['--dry-run' => true]);

        $this->assertSame(['method' => 'publishPlanned', 'dryRun' => true], $this->called);
    }

    // A dry run prints what each network would receive
    public function testADryRunPrintsThePayload(): void
    {
        $tester = $this->createTester(['bluesky' => ['status' => SocialPublisher::DRY_RUN, 'message' => 'configured', 'payload' => ['text' => 'Été à Annecy']]]);
        $tester->execute(['--dry-run' => true]);

        $this->assertStringContainsString('bluesky (configured)', $tester->getDisplay());
        $this->assertStringContainsString('"text": "Été à Annecy"', $tester->getDisplay());
    }

    // Without "--at", a page's draft is planned at the next quarter of an hour
    public function testAnUrlIsPlannedAtTheNextQuarterByDefault(): void
    {
        $before = time();
        $this->createTester([])->execute(['--url' => 'https://example.org/page', '--dry-run' => true]);
        $after = time();

        $this->assertSame('prepareUrl', $this->called['method']);
        $this->assertSame('https://example.org/page', $this->called['url']);
        $this->assertTrue($this->called['dryRun']);
        $at = $this->called['at']->getTimestamp();
        $this->assertSame(0, $at % SocialPlanner::QUARTER);
        $this->assertGreaterThan($before, $at);
        $this->assertLessThanOrEqual($after + SocialPlanner::QUARTER, $at);
    }

    // "--at" plans a page's draft at that moment, brought to the nearest quarter of an hour
    public function testAnUrlIsPlannedAtTheMomentGivenRounded(): void
    {
        $this->createTester([])->execute(['--url' => 'https://example.org/page', '--at' => '2026-10-10 18:08']);

        $this->assertSame('prepareUrl', $this->called['method']);
        $this->assertSame('2026-10-10 18:15:00', $this->called['at']->format('Y-m-d H:i:s'));
        $this->assertFalse($this->called['dryRun']);
    }

    // A moment that cannot be read fails the command before anything is prepared
    public function testAnUnreadableMomentFailsTheCommand(): void
    {
        $tester = $this->createTester([]);

        $this->assertSame(Command::FAILURE, $tester->execute(['--url' => 'https://example.org/page', '--at' => 'not a date']));
        $this->assertSame([], $this->called);
    }

    // A page that cannot be read fails the command with its reason
    public function testAPageThatCannotBeReadFailsTheCommand(): void
    {
        $tester = $this->createTester([], new \RuntimeException('The page answered 404.'));

        $this->assertSame(Command::FAILURE, $tester->execute(['--url' => 'https://example.org/page']));
        $this->assertStringContainsString('answered 404', $tester->getDisplay());
    }
}
