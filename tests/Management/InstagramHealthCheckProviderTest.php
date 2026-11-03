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
use c975L\SocialBundle\Management\InstagramHealthCheckProvider;
use c975L\SocialBundle\Service\InstagramLoginClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// The token renews itself: what is said is a renewal that stopped or a connection that ended
class InstagramHealthCheckProviderTest extends TestCase
{
    private function checkStatus(?string $expiresAt, bool $configured = true, string $accessToken = 'token'): ?string
    {
        $client = $this->createStub(InstagramLoginClient::class);
        $client->method('isConfigured')->willReturn($configured);
        $client->method('accessToken')->willReturn($accessToken);
        $client->method('expiresAt')->willReturn(null === $expiresAt ? null : new \DateTimeImmutable($expiresAt));

        $checks = new InstagramHealthCheckProvider($client, $this->createStub(UrlGeneratorInterface::class))->runChecks();

        return $checks[0]['status'] ?? null;
    }

    // No app, or never connected: a site whose Instagram goes through its Page is not broken here
    public function testNothingIsReportedWithoutAConnection(): void
    {
        $this->assertNull($this->checkStatus('+50 days', false));
        $this->assertNull($this->checkStatus(null));
    }

    // Its token emptied to go back to the Page, the date left behind says nothing any more
    public function testNothingIsReportedOnceTheTokenIsEmptied(): void
    {
        $this->assertNull($this->checkStatus('+50 days', accessToken: ''));
        $this->assertNull($this->checkStatus('-1 day', accessToken: ''));
    }

    public function testARenewedConnectionIsFine(): void
    {
        $this->assertSame(HealthCheckResult::STATUS_OK, $this->checkStatus('+50 days'));
    }

    // Days from its end, the nightly renewal has stopped; past its end, the account has to connect again
    public function testARenewalThatStoppedWarnsAndAConnectionThatEndedIsAnError(): void
    {
        $this->assertSame(HealthCheckResult::STATUS_WARNING, $this->checkStatus('+3 days'));
        $this->assertSame(HealthCheckResult::STATUS_ERROR, $this->checkStatus('-1 day'));
    }
}
