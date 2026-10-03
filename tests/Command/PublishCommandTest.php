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
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialSchedule;
use c975L\SocialBundle\Repository\SocialScheduleRepository;
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
    private function createTester(array $report, ?\Throwable $failure = null, ?SocialSchedule $slot = null): CommandTester
    {
        $socialPublisher = $this->createStub(SocialPublisher::class);
        $socialPublisher->method('prepareNext')->willReturnCallback(function (bool $force, bool $dryRun) use ($report): array {
            $this->called = ['method' => 'prepareNext', 'force' => $force, 'dryRun' => $dryRun];

            return $report;
        });
        $socialPublisher->method('prepareUrl')->willReturnCallback(function (string $url, bool $dryRun) use ($report, $failure): array {
            $this->called = ['method' => 'prepareUrl', 'url' => $url, 'dryRun' => $dryRun];
            if (null !== $failure) {
                throw $failure;
            }

            return $report;
        });

        $socialPublisher->method('prepareSlot')->willReturnCallback(function (SocialSchedule $slot, bool $dryRun) use ($report): array {
            $this->called = ['method' => 'prepareSlot', 'slot' => $slot->getName(), 'dryRun' => $dryRun];

            return $report;
        });

        $socialPublisher->method('prepareDrafts')->willReturnCallback(function (int $count): array {
            $this->called = ['method' => 'prepareDrafts', 'count' => $count];

            return [new SocialPost('gallery_media', '42', 'La sieste', 'https://example.org/42', null)];
        });

        $scheduleRepository = $this->createStub(SocialScheduleRepository::class);
        $scheduleRepository->method('find')->willReturn($slot);

        return new CommandTester(new PublishCommand($socialPublisher, $scheduleRepository));
    }

    // Most hourly runs have nothing due, which a cron must not read as a failure
    public function testNothingPreparedIsASuccess(): void
    {
        $tester = $this->createTester([]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Nothing prepared.', $tester->getDisplay());
    }

    // A refused post is kept, failed, on the screen - the run itself did its job
    public function testEachNetworkIsReportedAndARefusalIsNoFailure(): void
    {
        $tester = $this->createTester(['bluesky' => ['status' => 'failed', 'message' => 'Down'], 'other' => ['status' => 'draft', 'message' => '']]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('bluesky: failed Down', $tester->getDisplay());
        $this->assertStringContainsString('other: draft', $tester->getDisplay());
    }

    public function testTheOptionsArePassedThrough(): void
    {
        $tester = $this->createTester([]);
        $tester->execute(['--force' => true, '--dry-run' => true]);

        $this->assertSame(['method' => 'prepareNext', 'force' => true, 'dryRun' => true], $this->called);
    }

    public function testADryRunPrintsThePayload(): void
    {
        $tester = $this->createTester(['bluesky' => ['status' => SocialPublisher::DRY_RUN, 'message' => 'review', 'payload' => ['text' => 'Été à Annecy']]]);
        $tester->execute(['--dry-run' => true]);

        $this->assertStringContainsString('bluesky (review)', $tester->getDisplay());
        $this->assertStringContainsString('"text": "Été à Annecy"', $tester->getDisplay());
    }

    public function testAnUrlPreparesThatPage(): void
    {
        $tester = $this->createTester([]);
        $tester->execute(['--url' => 'https://example.org/page']);

        $this->assertSame(['method' => 'prepareUrl', 'url' => 'https://example.org/page', 'dryRun' => false], $this->called);
    }

    public function testAPageThatCannotBeReadFailsTheCommand(): void
    {
        $tester = $this->createTester([], new \RuntimeException('The page answered 404.'));

        $this->assertSame(Command::FAILURE, $tester->execute(['--url' => 'https://example.org/page']));
        $this->assertStringContainsString('answered 404', $tester->getDisplay());
    }

    public function testASlotIsRunByItsId(): void
    {
        $tester = $this->createTester([], slot: new SocialSchedule()->setName('Morning'));
        $tester->execute(['--slot' => '3']);

        $this->assertSame(['method' => 'prepareSlot', 'slot' => 'Morning', 'dryRun' => false], $this->called);
    }

    // Its task only goes away when the worker starts again: meanwhile, a slot turned off prepares nothing
    public function testADisabledOrDeletedSlotPreparesNothing(): void
    {
        $this->assertSame(Command::SUCCESS, $this->createTester([], slot: new SocialSchedule()->setName('Morning')->setEnabled(false))->execute(['--slot' => '3']));
        $this->assertSame([], $this->called);

        $this->assertSame(Command::SUCCESS, $this->createTester([])->execute(['--slot' => '3']));
        $this->assertSame([], $this->called);
    }

    // A batch of drafts lists what was prepared, for whoever reads them next
    public function testDraftsArePreparedInABatch(): void
    {
        $tester = $this->createTester([]);

        $this->assertSame(0, $tester->execute(['--drafts' => '5']));
        $this->assertSame(['method' => 'prepareDrafts', 'count' => 5], $this->called);
        $this->assertStringContainsString('La sieste', $tester->getDisplay());
    }

    // A draft is already a preview: "--dry-run" there would save real posts
    public function testDraftsRefuseADryRun(): void
    {
        $this->assertSame(Command::INVALID, $this->createTester([])->execute(['--drafts' => '5', '--dry-run' => true]));
        $this->assertSame([], $this->called);
    }
}
