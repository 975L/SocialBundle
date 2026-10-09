<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Command;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Command\SeriesEndingCommand;
use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Repository\SocialSeriesRepository;
use c975L\SocialBundle\Service\SocialAdminMailer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\Translation\TranslatorInterface;

class SeriesEndingCommandTest extends TestCase
{
    /** @var list<string> */
    private array $sent = [];

    /** @param list<array{series: SocialSeries, lastPlannedAt: \DateTimeImmutable}> $rows */
    private function tester(array $rows, string $days = '3', ?string $error = null): CommandTester
    {
        $config = $this->createStub(ConfigServiceInterface::class);
        $config->method('get')->willReturnMap([['social-series-ending-days', $days]]);
        $repository = $this->createStub(SocialSeriesRepository::class);
        $repository->method('findWithLastPlannedAt')->willReturn($rows);
        $mailer = $this->createStub(SocialAdminMailer::class);
        $mailer->method('send')->willReturnCallback(function (string $subject, string $body) use ($error): ?string {
            $this->sent[] = $body;

            return $error;
        });
        $mailer->method('url')->willReturnCallback(static fn (string $route, array $parameters): string => 'https://example.org/management/social-calendar?date=' . $parameters['date']);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new CommandTester(new SeriesEndingCommand($repository, $config, $mailer, $this->createStub(EntityManagerInterface::class), $translator));
    }

    private function series(): SocialSeries
    {
        return new SocialSeries('Photo du soir', 7, 'days', 1, [], 'text', 'Photo du soir', [], ['bluesky']);
    }

    // A series ending within the delay is announced once, with a link to its last week; the next night says nothing more
    public function testASeriesEndingIsAnnouncedOnce(): void
    {
        $rows = [['series' => $this->series(), 'lastPlannedAt' => new \DateTimeImmutable('+2 days 21:00')]];

        $this->assertSame(Command::SUCCESS, $this->tester($rows)->execute([]));
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('Photo du soir', $this->sent[0]);
        $this->assertStringContainsString('https://example.org/management/social-calendar?date=', $this->sent[0]);

        $this->tester($rows)->execute([]);
        $this->assertCount(1, $this->sent);
    }

    // An announcement not sent is a warning, not a failure alerting every night, and is tried again the next night
    public function testAnAnnouncementNotSentIsTriedAgain(): void
    {
        $rows = [['series' => $this->series(), 'lastPlannedAt' => new \DateTimeImmutable('+2 days 21:00')]];
        $tester = $this->tester($rows, error: 'No recipient');

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('No recipient', $tester->getDisplay());

        $this->tester($rows, error: 'No recipient')->execute([]);
        $this->assertCount(2, $this->sent);
    }

    // A series ending later, or a delay of 0, announces nothing
    public function testNothingIsAnnouncedOutsideTheDelay(): void
    {
        $this->tester([['series' => $this->series(), 'lastPlannedAt' => new \DateTimeImmutable('+10 days')]])->execute([]);
        $this->tester([['series' => $this->series(), 'lastPlannedAt' => new \DateTimeImmutable('+1 day')]], '0')->execute([]);

        $this->assertSame([], $this->sent);
    }
}
