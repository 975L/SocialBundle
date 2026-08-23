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
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Management\GoogleReviewsHealthCheckProvider;
use c975L\SocialBundle\Service\GoogleBusinessProfileSource;
use c975L\SocialBundle\Service\GoogleOAuthClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// The one failure this bundle has that nobody sees: the import stops while the site keeps serving the reviews of the last successful run
class GoogleReviewsHealthCheckProviderTest extends TestCase
{
    // Nothing to watch over: UiBundle's switch is off, so no imported review is displayed anywhere
    public function testNothingIsReportedWhileTheReviewsAreOff(): void
    {
        $this->assertSame([], $this->provider(reviewsEnabled: false)->runChecks());
    }

    // A site collecting only what its own visitors write never declared a Google application, and is not a site whose import is broken
    public function testNothingIsReportedWithoutAGoogleApplication(): void
    {
        $this->assertSame([], $this->provider(configured: false)->runChecks());
    }

    public function testStoredKeysNeverConnectedAreReportedAsAWarning(): void
    {
        $checks = $this->provider(connected: false)->runChecks();

        $this->assertCount(1, $checks);
        $this->assertSame(HealthCheckResult::STATUS_WARNING, $checks[0]['status']);
        $this->assertStringContainsString('never connected', $checks[0]['summary']);
        $this->assertSame('/social/google/connect', $checks[0]['editUrl']);
    }

    // A revoked token reads from the config exactly like a working one, so asking Google is the whole point of the check
    public function testARefusedConnectionIsReportedWithGoogleSReason(): void
    {
        $checks = $this->provider(accessTokenError: 'Google refused the token request (400): invalid_grant')->runChecks();

        $this->assertSame(HealthCheckResult::STATUS_ERROR, $checks[0]['status']);
        $this->assertStringContainsString('invalid_grant', $checks[0]['summary']);
    }

    // The token works and the sync still reads nothing: the listing was never picked
    public function testAConnectionPointingAtNoListingIsReportedAsAWarning(): void
    {
        $checks = $this->provider(listingSelected: false)->runChecks();

        $this->assertSame(HealthCheckResult::STATUS_WARNING, $checks[0]['status']);
        $this->assertStringContainsString('no listing is selected', $checks[0]['summary']);
    }

    public function testAWorkingConnectionIsReportedOk(): void
    {
        $checks = $this->provider()->runChecks();

        $this->assertSame(HealthCheckResult::STATUS_OK, $checks[0]['status']);
        $this->assertSame(GoogleBusinessProfileSource::NAME, $checks[0]['url']);
        $this->assertSame(GoogleBusinessProfileSource::NAME, $checks[0]['details']['source']);
    }

    private function provider(
        bool $reviewsEnabled = true,
        bool $configured = true,
        bool $connected = true,
        ?string $accessTokenError = null,
        bool $listingSelected = true,
    ): GoogleReviewsHealthCheckProvider {
        $googleOAuthClient = $this->createStub(GoogleOAuthClient::class);
        $googleOAuthClient->method('isConfigured')->willReturn($configured);
        $googleOAuthClient->method('isConnected')->willReturn($connected);

        if (null === $accessTokenError) {
            $googleOAuthClient->method('getAccessToken')->willReturn('access-token');
        } else {
            $googleOAuthClient->method('getAccessToken')->willThrowException(new \RuntimeException($accessTokenError));
        }

        $source = $this->createStub(GoogleBusinessProfileSource::class);
        $source->method('isConfigured')->willReturn($listingSelected);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('ui-enable-reviews');
        $configService->method('getBool')->willReturn($reviewsEnabled);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/social/google/connect');

        return new GoogleReviewsHealthCheckProvider($googleOAuthClient, $source, $configService, $urlGenerator);
    }
}
