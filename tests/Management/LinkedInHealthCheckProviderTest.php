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
use c975L\SocialBundle\Management\LinkedInHealthCheckProvider;
use c975L\SocialBundle\Service\LinkedInClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// The token ends on its own: said ahead, while reconnecting is one click
class LinkedInHealthCheckProviderTest extends TestCase
{
    private function checkStatus(?string $expiresAt, bool $configured = true): ?string
    {
        $client = $this->createStub(LinkedInClient::class);
        $client->method('isConfigured')->willReturn($configured);
        $client->method('expiresAt')->willReturn(null === $expiresAt ? null : new \DateTimeImmutable($expiresAt));

        $checks = new LinkedInHealthCheckProvider($client, $this->createStub(UrlGeneratorInterface::class))->runChecks();

        return $checks[0]['status'] ?? null;
    }

    // No app, or never connected: a site not posting on LinkedIn is not broken there
    public function testNothingIsReportedWithoutAConnection(): void
    {
        $this->assertNull($this->checkStatus('+30 days', false));
        $this->assertNull($this->checkStatus(null));
    }

    public function testTheEndIsSaidAFortnightAheadThenOnceItCame(): void
    {
        $this->assertSame(HealthCheckResult::STATUS_OK, $this->checkStatus('+30 days'));
        $this->assertSame(HealthCheckResult::STATUS_WARNING, $this->checkStatus('+10 days'));
        $this->assertSame(HealthCheckResult::STATUS_ERROR, $this->checkStatus('-1 day'));
    }
}
