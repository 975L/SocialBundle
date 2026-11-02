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
use c975L\SocialBundle\Management\TikTokHealthCheckProvider;
use c975L\SocialBundle\Service\TikTokClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// The tokens renew themselves: what is said is a renewal that stopped or a connection that ended
class TikTokHealthCheckProviderTest extends TestCase
{
    private function checkStatus(?string $refreshExpiresAt, ?string $expiresAt = '+1 hour', bool $configured = true): ?string
    {
        $client = $this->createStub(TikTokClient::class);
        $client->method('isConfigured')->willReturn($configured);
        $client->method('refreshExpiresAt')->willReturn(null === $refreshExpiresAt ? null : new \DateTimeImmutable($refreshExpiresAt));
        $client->method('expiresAt')->willReturn(null === $expiresAt ? null : new \DateTimeImmutable($expiresAt));

        $checks = new TikTokHealthCheckProvider($client, $this->createStub(UrlGeneratorInterface::class))->runChecks();

        return $checks[0]['status'] ?? null;
    }

    // No app, or never connected: a site not posting there is not broken there
    public function testNothingIsReportedWithoutAConnection(): void
    {
        $this->assertNull($this->checkStatus('+300 days', configured: false));
        $this->assertNull($this->checkStatus(null));
    }

    // A token lasting 2 hours over until the next night is the day's ordinary state, a night's run a little late included
    public function testARenewedConnectionIsFine(): void
    {
        $this->assertSame(HealthCheckResult::STATUS_OK, $this->checkStatus('+300 days'));
        $this->assertSame(HealthCheckResult::STATUS_OK, $this->checkStatus('+300 days', '-26 hours'));
    }

    // An access token not renewed for a day and a half says the nightly renewal stopped, a refresh token past its end that the account has to connect again
    public function testARenewalThatStoppedOrAConnectionThatEndedIsAnError(): void
    {
        $this->assertSame(HealthCheckResult::STATUS_ERROR, $this->checkStatus('+300 days', '-2 days'));
        $this->assertSame(HealthCheckResult::STATUS_ERROR, $this->checkStatus('-1 day', '-2 days'));
    }
}
