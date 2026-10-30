<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Management;

use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\SocialBundle\Management\PinterestHealthCheckProvider;
use c975L\SocialBundle\Service\PinterestClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// The tokens renew themselves: what is said is a renewal that stopped, a connection that ended, or a board never picked
class PinterestHealthCheckProviderTest extends TestCase
{
    private function checkStatus(?string $refreshExpiresAt, ?string $expiresAt = '+20 days', bool $board = true, bool $configured = true): ?string
    {
        $client = $this->createStub(PinterestClient::class);
        $client->method('isConfigured')->willReturn($configured);
        $client->method('refreshExpiresAt')->willReturn(null === $refreshExpiresAt ? null : new \DateTimeImmutable($refreshExpiresAt));
        $client->method('expiresAt')->willReturn(null === $expiresAt ? null : new \DateTimeImmutable($expiresAt));
        $client->method('hasBoard')->willReturn($board);

        $checks = new PinterestHealthCheckProvider($client, $this->createStub(UrlGeneratorInterface::class))->runChecks();

        return $checks[0]['status'] ?? null;
    }

    // No app, or never connected: a site not pinning is not broken there
    public function testNothingIsReportedWithoutAConnection(): void
    {
        $this->assertNull($this->checkStatus('+50 days', configured: false));
        $this->assertNull($this->checkStatus(null));
    }

    public function testARenewedConnectionWithABoardIsFine(): void
    {
        $this->assertSame(HealthCheckResult::STATUS_OK, $this->checkStatus('+50 days'));
    }

    // A connection without a board pins nothing
    public function testAMissingBoardIsAWarning(): void
    {
        $this->assertSame(HealthCheckResult::STATUS_WARNING, $this->checkStatus('+50 days', board: false));
    }

    // An access token past its end says the nightly renewal stopped, a refresh token past its end that the account has to connect again
    public function testARenewalThatStoppedOrAConnectionThatEndedIsAnError(): void
    {
        $this->assertSame(HealthCheckResult::STATUS_ERROR, $this->checkStatus('+50 days', '-1 day'));
        $this->assertSame(HealthCheckResult::STATUS_ERROR, $this->checkStatus('-1 day', '-1 day'));
    }
}
