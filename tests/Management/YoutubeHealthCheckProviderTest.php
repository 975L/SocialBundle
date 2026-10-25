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
use c975L\SocialBundle\Management\YoutubeHealthCheckProvider;
use c975L\SocialBundle\Service\YoutubeClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// A revoked refresh token is said before the planned videos fail on it
class YoutubeHealthCheckProviderTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function checks(bool $configured = true, bool $connected = true, ?string $tokenError = null): array
    {
        $client = $this->createStub(YoutubeClient::class);
        $client->method('isConfigured')->willReturn($configured);
        $client->method('isConnected')->willReturn($connected);
        if (null === $tokenError) {
            $client->method('getAccessToken')->willReturn('access');
        } else {
            $client->method('getAccessToken')->willThrowException(new \RuntimeException($tokenError));
        }

        return new YoutubeHealthCheckProvider($client, $this->createStub(UrlGeneratorInterface::class))->runChecks();
    }

    // No Google application, or no channel: a site not posting on YouTube is not broken there
    public function testNothingIsReportedWithoutAConnection(): void
    {
        $this->assertSame([], $this->checks(configured: false));
        $this->assertSame([], $this->checks(connected: false));
    }

    public function testARefusedTokenIsAnErrorCarryingGooglesReason(): void
    {
        $checks = $this->checks(tokenError: 'invalid_grant');

        $this->assertSame(HealthCheckResult::STATUS_ERROR, $checks[0]['status']);
        $this->assertStringContainsString('invalid_grant', $checks[0]['summary']);
    }

    public function testAWorkingConnectionIsReportedOk(): void
    {
        $this->assertSame(HealthCheckResult::STATUS_OK, $this->checks()[0]['status']);
    }
}
