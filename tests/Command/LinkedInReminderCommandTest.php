<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Command;

use c975L\SocialBundle\Command\LinkedInReminderCommand;
use c975L\SocialBundle\Service\LinkedInClient;
use c975L\SocialBundle\Service\SocialAdminMailer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\Translation\TranslatorInterface;

class LinkedInReminderCommandTest extends TestCase
{
    /** @var list<string> */
    private array $mailed = [];

    // The token ending $days days from today, at noon - or at the moment given
    private function remind(?int $days, bool $connected = true, ?\DateTimeImmutable $expiresAt = null): int
    {
        $client = $this->createStub(LinkedInClient::class);
        $client->method('isConnected')->willReturn($connected);
        $client->method('expiresAt')->willReturn($expiresAt ?? (null === $days ? null : new \DateTimeImmutable('today')->modify(sprintf('+%d days', $days))->setTime(12, 0)));

        $mailer = $this->createStub(SocialAdminMailer::class);
        $mailer->method('url')->willReturn('https://example.org/management/social-connections');
        $mailer->method('send')->willReturnCallback(function (string $subject, string $body): ?string {
            $this->mailed[] = $subject . "\n" . $body;

            return null;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new CommandTester(new LinkedInReminderCommand($client, $mailer, $translator))->execute([]);
    }

    // One email 14 days ahead and one 3 days ahead, with the screen to connect again
    public function testTheReminderGoesFourteenThenThreeDaysAhead(): void
    {
        $this->assertSame(Command::SUCCESS, $this->remind(14));
        $this->assertSame(Command::SUCCESS, $this->remind(3));

        $this->assertCount(2, $this->mailed);
        $this->assertStringContainsString('https://example.org/management/social-connections', $this->mailed[0]);
    }

    // An end stored with another offset than the server's is counted on the server's calendar: late on the 14th day here is the 15th two hours east
    public function testTheDaysLeftAreCountedInTheServersTimezone(): void
    {
        $local = new \DateTimeImmutable('today')->modify('+14 days')->setTime(23, 30);
        $east = new \DateTimeZone(sprintf('%+03d:00', intdiv($local->getOffset(), 3600) + 2));

        $this->remind(null, expiresAt: $local->setTimezone($east));

        $this->assertCount(1, $this->mailed);
    }

    // Any other day, an expired token or no connection: nothing
    public function testNothingGoesOnTheOtherDays(): void
    {
        foreach ([15, 13, 4, 0, -1, null] as $days) {
            $this->remind($days);
        }
        $this->remind(14, connected: false);

        $this->assertSame([], $this->mailed);
    }
}
