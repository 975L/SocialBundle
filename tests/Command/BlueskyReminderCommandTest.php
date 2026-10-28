<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Command;

use c975L\SocialBundle\Command\BlueskyReminderCommand;
use c975L\SocialBundle\Service\BlueskyOAuthClient;
use c975L\SocialBundle\Service\SocialAdminMailer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\Translation\TranslatorInterface;

class BlueskyReminderCommandTest extends TestCase
{
    /** @var list<string> */
    private array $mailed = [];

    private int $marked = 0;

    // A connection whose permissions are out of date or not, already told or not - the mailer failing when asked
    private function remind(bool $outdated, bool $notified = false, ?string $error = null): int
    {
        $client = $this->createStub(BlueskyOAuthClient::class);
        $client->method('needsReconnection')->willReturn($outdated);
        $client->method('isReconnectionNotified')->willReturn($notified);
        $client->method('markReconnectionNotified')->willReturnCallback(function (): void {
            ++$this->marked;
        });

        $mailer = $this->createStub(SocialAdminMailer::class);
        $mailer->method('url')->willReturn('https://example.org/management/social-connections');
        $mailer->method('send')->willReturnCallback(function (string $subject, string $body) use ($error): ?string {
            $this->mailed[] = $subject . "\n" . $body;

            return $error;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new CommandTester(new BlueskyReminderCommand($client, $mailer, $translator))->execute([]);
    }

    // One email with the screen to connect again, then marked so the next nights stay quiet
    public function testAnOutdatedConnectionIsToldOnce(): void
    {
        $this->assertSame(Command::SUCCESS, $this->remind(true));

        $this->assertCount(1, $this->mailed);
        $this->assertStringContainsString('https://example.org/management/social-connections', $this->mailed[0]);
        $this->assertSame(1, $this->marked);
    }

    // Up to date, or already told: nothing
    public function testNothingGoesWhenUpToDateOrAlreadyTold(): void
    {
        $this->remind(false);
        $this->remind(true, notified: true);

        $this->assertSame([], $this->mailed);
        $this->assertSame(0, $this->marked);
    }

    // An email that did not go is not marked, the next night trying again
    public function testAFailedEmailIsTriedAgain(): void
    {
        $this->assertSame(Command::SUCCESS, $this->remind(true, error: 'SMTP down'));

        $this->assertSame(0, $this->marked);
    }
}
