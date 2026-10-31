<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Command;

use c975L\SocialBundle\Command\PinterestRefreshCommand;
use c975L\SocialBundle\Service\PinterestClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

class PinterestRefreshCommandTest extends TestCase
{
    use ConnectionCommandTestTrait;

    private function refresh(bool $needsRefresh = true, bool $connected = true, ?\RuntimeException $refusal = null, string $expiresAt = '+6 days 12 hours', string $refreshExpiresAt = '+36 days'): int
    {
        $client = $this->refreshingClient(PinterestClient::class, $connected, $refusal, $expiresAt, $refreshExpiresAt);
        $client->method('needsRefresh')->willReturn($needsRefresh);

        return $this->runCommand(PinterestRefreshCommand::class, $client);
    }

    // An access token near its end is renewed, quietly
    public function testATokenNearItsEndIsRenewed(): void
    {
        $this->assertSame(Command::SUCCESS, $this->refresh());

        $this->assertSame(1, $this->refreshed);
        $this->assertSame([], $this->mailed);
    }

    // A token still far from its end, or no connection at all: nothing asked of Pinterest
    public function testNothingIsAskedWhenNoRenewalIsDue(): void
    {
        $this->refresh(false);
        $this->refresh(connected: false);

        $this->assertSame(0, $this->refreshed);
    }

    // A renewal Pinterest refuses is mailed the first night, with the screen to connect again, the run still a success - an error would raise a CRITICAL alert
    public function testARefusedRenewalIsMailed(): void
    {
        $this->assertSame(Command::SUCCESS, $this->refresh(refusal: new \RuntimeException('Pinterest refused the connection: invalid_grant')));

        $this->assertCount(1, $this->mailed);
        $this->assertStringContainsString('https://example.org/management/social-connections', $this->mailed[0]);
    }

    // The nights after the first only warn, until the last reminder before the connection ends
    public function testARefusalIsMailedOnlyOnFixedDays(): void
    {
        $refusal = new \RuntimeException('Pinterest refused the connection: invalid_grant');

        $this->refresh(refusal: $refusal, expiresAt: '+3 days');
        $this->assertSame([], $this->mailed);

        $this->refresh(refusal: $refusal, expiresAt: '-20 days', refreshExpiresAt: 'today +' . PinterestRefreshCommand::LAST_REMINDER . ' days 12 hours');
        $this->assertCount(1, $this->mailed);
    }
}
