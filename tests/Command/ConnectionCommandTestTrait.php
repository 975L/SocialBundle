<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Command;

use c975L\SocialBundle\Service\SocialAdminMailer;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\Translation\TranslatorInterface;

// The nightly commands watching a network's connection - built on its client, the admin mailer and the translator - run with the emails kept in $mailed and the renewals counted in $refreshed
trait ConnectionCommandTestTrait
{
    /** @var list<string> */
    private array $mailed = [];

    private int $refreshed = 0;

    // The command run once, its emails kept - the mailer answering the given error, as when the sending fails - and the translator answering the keys
    /** @param class-string<Command> $command */
    private function runCommand(string $command, object $client, ?string $mailError = null): int
    {
        $mailer = $this->createStub(SocialAdminMailer::class);
        $mailer->method('url')->willReturn('https://example.org/management/social-connections');
        $mailer->method('send')->willReturnCallback(function (string $subject, string $body) use ($mailError): ?string {
            $this->mailed[] = $subject . "\n" . $body;

            return $mailError;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new CommandTester(new $command($client, $mailer, $translator))->execute([]);
    }

    // A connected client with its two ends, each renewal counted in $refreshed and refused with the given exception
    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return Stub&T
     */
    private function refreshingClient(string $class, bool $connected, ?\RuntimeException $refusal, string $expiresAt, string $refreshExpiresAt): Stub
    {
        $client = $this->createStub($class);
        $client->method('expiresAt')->willReturn(new \DateTimeImmutable($expiresAt));
        $client->method('refreshExpiresAt')->willReturn(new \DateTimeImmutable($refreshExpiresAt));
        $client->method('isConfigured')->willReturn(true);
        $client->method('isConnected')->willReturn($connected);
        $client->method('refresh')->willReturnCallback(function () use ($refusal): void {
            ++$this->refreshed;
            if (null !== $refusal) {
                throw $refusal;
            }
        });

        return $client;
    }
}
