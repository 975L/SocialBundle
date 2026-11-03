<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Command;

use c975L\SocialBundle\Command\InstagramRefreshCommand;
use c975L\SocialBundle\Service\InstagramLoginClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

class InstagramRefreshCommandTest extends TestCase
{
    use ConnectionCommandTestTrait;

    private function refresh(bool $needsRefresh = true, bool $connected = true, ?\RuntimeException $refusal = null, string $expiresAt = '+29 days 12 hours'): int
    {
        $client = $this->createStub(InstagramLoginClient::class);
        $client->method('expiresAt')->willReturn(new \DateTimeImmutable($expiresAt));
        $client->method('isConfigured')->willReturn(true);
        $client->method('isConnected')->willReturn($connected);
        $client->method('needsRefresh')->willReturn($needsRefresh);
        $client->method('refresh')->willReturnCallback($this->countedRefresh($refusal));

        return $this->runCommand(InstagramRefreshCommand::class, $client);
    }

    // A token near its end is renewed, quietly
    public function testATokenNearItsEndIsRenewed(): void
    {
        $this->assertSame(Command::SUCCESS, $this->refresh());

        $this->assertSame(1, $this->refreshed);
        $this->assertSame([], $this->mailed);
    }

    // A token still far from its end, or no connection at all: nothing asked of Instagram
    public function testNothingIsAskedWhenNoRenewalIsDue(): void
    {
        $this->refresh(false);
        $this->refresh(connected: false);

        $this->assertSame(0, $this->refreshed);
    }

    // A renewal Instagram refuses is mailed the first night, with the screen to connect again, the run still a success - an error would raise a CRITICAL alert
    public function testARefusedRenewalIsMailed(): void
    {
        $this->assertSame(Command::SUCCESS, $this->refresh(refusal: new \RuntimeException('Meta refused /refresh_access_token: expired')));

        $this->assertCount(1, $this->mailed);
        $this->assertStringContainsString('https://example.org/management/social-connections', $this->mailed[0]);
    }

    // The nights after the first only warn, until the last reminder before the token ends
    public function testARefusalIsMailedOnlyOnFixedDays(): void
    {
        $refusal = new \RuntimeException('Meta refused /refresh_access_token: expired');

        $this->refresh(refusal: $refusal, expiresAt: '+15 days');
        $this->assertSame([], $this->mailed);

        $this->refresh(refusal: $refusal, expiresAt: 'today +' . InstagramRefreshCommand::LAST_REMINDER . ' days 12 hours');
        $this->assertCount(1, $this->mailed);
    }
}
