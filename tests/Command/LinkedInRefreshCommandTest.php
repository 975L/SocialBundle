<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Command;

use c975L\SocialBundle\Command\LinkedInRefreshCommand;
use c975L\SocialBundle\Service\LinkedInClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class LinkedInRefreshCommandTest extends TestCase
{
    private int $refreshed = 0;

    // The command run once, each renewal counted in $refreshed and refused with the given exception
    /** @param array<string, mixed> $input */
    private function execute(bool $canRefresh = true, bool $needsRefresh = true, ?\RuntimeException $refusal = null, array $input = [], bool $isConfigured = true): CommandTester
    {
        $client = $this->createStub(LinkedInClient::class);
        $client->method('isConfigured')->willReturn($isConfigured);
        $client->method('canRefresh')->willReturn($canRefresh);
        $client->method('needsRefresh')->willReturn($needsRefresh);
        $client->method('refresh')->willReturnCallback(function () use ($refusal): void {
            ++$this->refreshed;
            if (null !== $refusal) {
                throw $refusal;
            }
        });

        $tester = new CommandTester(new LinkedInRefreshCommand($client));
        $tester->execute($input);

        return $tester;
    }

    // A token near its end is renewed
    public function testATokenNearItsEndIsRenewed(): void
    {
        $this->assertSame(Command::SUCCESS, $this->execute()->getStatusCode());
        $this->assertSame(1, $this->refreshed);
    }

    // A token far from its end, no refresh token - a profile app - or no app declared: nothing asked of LinkedIn
    public function testNothingIsAskedWhenNoRenewalIsDue(): void
    {
        $this->execute(needsRefresh: false);
        $this->execute(canRefresh: false);
        $this->execute(isConfigured: false);

        $this->assertSame(0, $this->refreshed);
    }

    // --force renews a token far from its end, but never without a refresh token
    public function testForceRenewsWhateverTheEnd(): void
    {
        $this->execute(needsRefresh: false, input: ['--force' => true]);
        $this->assertSame(1, $this->refreshed);

        $this->execute(canRefresh: false, input: ['--force' => true]);
        $this->assertSame(1, $this->refreshed);
    }

    // A refusal says LinkedIn's words, the run still a success - an error would raise a CRITICAL alert, and the reminders do the telling
    public function testARefusalOnlyWarns(): void
    {
        $tester = $this->execute(refusal: new \RuntimeException('LinkedIn refused the refresh: revoked'));

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('revoked', $tester->getDisplay());
    }
}
