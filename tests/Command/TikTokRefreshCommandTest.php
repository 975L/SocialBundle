<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Command;

use c975L\SocialBundle\Command\TikTokRefreshCommand;
use c975L\SocialBundle\Service\TikTokClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

class TikTokRefreshCommandTest extends TestCase
{
    use ConnectionCommandTestTrait;

    private function refresh(bool $connected = true, ?\RuntimeException $refusal = null, string $expiresAt = '+2 hours', string $refreshExpiresAt = '+300 days'): int
    {
        return $this->runCommand(TikTokRefreshCommand::class, $this->refreshingClient(TikTokClient::class, $connected, $refusal, $expiresAt, $refreshExpiresAt));
    }

    // A token lasting 24 hours is renewed every night, quietly
    public function testTheTokensAreRenewedEveryNight(): void
    {
        $this->assertSame(Command::SUCCESS, $this->refresh());

        $this->assertSame(1, $this->refreshed);
        $this->assertSame([], $this->mailed);
    }

    // No connection: nothing asked of TikTok
    public function testNothingIsAskedWithoutAConnection(): void
    {
        $this->refresh(connected: false);

        $this->assertSame(0, $this->refreshed);
    }

    // A renewal TikTok refuses is mailed the first night, with the screen to connect again, the run still a success - an error would raise a CRITICAL alert
    public function testARefusedRenewalIsMailed(): void
    {
        $this->assertSame(Command::SUCCESS, $this->refresh(refusal: new \RuntimeException('TikTok refused the connection: invalid_grant')));

        $this->assertCount(1, $this->mailed);
        $this->assertStringContainsString('https://example.org/management/social-connections', $this->mailed[0]);
    }

    // The nights after the first only warn, until the last reminder before the connection ends
    public function testARefusalIsMailedOnlyOnFixedDays(): void
    {
        $refusal = new \RuntimeException('TikTok refused the connection: invalid_grant');

        $this->refresh(refusal: $refusal, expiresAt: '-3 days');
        $this->assertSame([], $this->mailed);

        // The second night, its run a little earlier than the first one's, stays quiet
        $this->refresh(refusal: $refusal, expiresAt: '-23 hours 59 minutes');
        $this->assertSame([], $this->mailed);

        $this->refresh(refusal: $refusal, expiresAt: '-20 days', refreshExpiresAt: 'today +' . TikTokRefreshCommand::LAST_REMINDER . ' days 12 hours');
        $this->assertCount(1, $this->mailed);
    }
}
