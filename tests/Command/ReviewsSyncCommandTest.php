<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Command;

use c975L\SocialBundle\Command\ReviewsSyncCommand;
use c975L\SocialBundle\Service\ReviewSynchronizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ReviewsSyncCommandTest extends TestCase
{
    /**
     * @param array<string, int> $imported
     */
    private function createTester(array $imported = [], ?\Throwable $failure = null): CommandTester
    {
        $reviewSynchronizer = $this->createStub(ReviewSynchronizer::class);

        if (null === $failure) {
            $reviewSynchronizer->method('synchronize')->willReturn($imported);
        } else {
            $reviewSynchronizer->method('synchronize')->willThrowException($failure);
        }

        return new CommandTester(new ReviewsSyncCommand($reviewSynchronizer));
    }

    public function testExecuteReportsHowManyReviewsEachSourceImported(): void
    {
        $tester = $this->createTester(['google' => 12]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('google: 12 review(s) synchronized.', $tester->getDisplay());
    }

    // A site that configured no source yet is the normal state, not a failure worth waking a cron up over
    public function testExecuteSucceedsWithAWarningWhenNoSourceIsConfigured(): void
    {
        $tester = $this->createTester([]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('No configured review source', $tester->getDisplay());
    }

    // The option restricts the run to one platform, which is what the synchronizer has to receive rather than a null
    public function testExecutePassesTheSourceOptionThrough(): void
    {
        $received = 'untouched';
        $reviewSynchronizer = $this->createStub(ReviewSynchronizer::class);
        $reviewSynchronizer->method('synchronize')->willReturnCallback(
            function (?string $only) use (&$received): array {
                $received = $only;

                return ['google' => 3];
            }
        );

        new CommandTester(new ReviewsSyncCommand($reviewSynchronizer))->execute(['--source' => 'google']);

        $this->assertSame('google', $received);
    }

    public function testExecuteSynchronizesEverySourceWithoutTheOption(): void
    {
        $received = 'untouched';
        $reviewSynchronizer = $this->createStub(ReviewSynchronizer::class);
        $reviewSynchronizer->method('synchronize')->willReturnCallback(
            function (?string $only) use (&$received): array {
                $received = $only;

                return ['google' => 3];
            }
        );

        new CommandTester(new ReviewsSyncCommand($reviewSynchronizer))->execute([]);

        $this->assertNull($received);
    }

    // A platform answering with an error has to make the cron fail, or a listing that stopped importing would go unnoticed
    public function testExecuteFailsWhenASourceThrows(): void
    {
        $tester = $this->createTester(failure: new \RuntimeException('Google answered 403'));

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('Google answered 403', $tester->getDisplay());
    }
}
