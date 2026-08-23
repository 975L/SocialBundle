<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Management;

use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Management\HealthCheckProviderInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Service\GoogleBusinessProfileSource;
use c975L\SocialBundle\Service\GoogleOAuthClient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// Whether the Google connection still works, which is the one place this bundle fails without anyone seeing it: the refresh token is revoked by a password change, by an owner leaving the listing, or on its own every seven days while the Cloud project is unpublished - and the sync then stops importing while the site keeps serving the reviews of the last successful run, so nothing on the page looks wrong
// Run from c975l:health-check:run only - it goes to Google's token endpoint, and no dashboard page may block on that
class GoogleReviewsHealthCheckProvider implements HealthCheckProviderInterface
{
    // Named here rather than restated wherever a row of this kind is picked out
    public const string KIND = 'social-google-reviews';

    public function __construct(
        private readonly GoogleOAuthClient $googleOAuthClient,
        private readonly GoogleBusinessProfileSource $googleBusinessProfileSource,
        private readonly ConfigServiceInterface $configService,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getKind(): string
    {
        return self::KIND;
    }

    public function runChecks(): array
    {
        // Reviews off, or no Google application declared at all: a site collecting only what its own visitors write is not a site whose import is broken. UiBundle owns the switch, this bundle only feeds it (see ReviewSynchronizer)
        if (
            !$this->configService->getBool($this->configService->get('ui-enable-reviews'))
            || !$this->googleOAuthClient->isConfigured()
        ) {
            return [];
        }

        return [$this->check()];
    }

    /**
     * @return array{url: string, label: string, status: HealthCheckResult::STATUS_*, summary: string, details: array<string, mixed>, editUrl: string}
     */
    private function check(): array
    {
        $row = [
            'url' => GoogleBusinessProfileSource::NAME,
            'label' => 'Google Business Profile',
            'details' => ['source' => GoogleBusinessProfileSource::NAME],
            'editUrl' => $this->urlGenerator->generate('social_google_oauth_connect'),
        ];

        // The keys are in, but the consent screen was never walked through: half a setup imports nothing, and the row is what says which half is missing
        if (!$this->googleOAuthClient->isConnected()) {
            return [
                ...$row,
                'status' => HealthCheckResult::STATUS_WARNING,
                'summary' => 'The Google keys are stored but the site was never connected: no review can be imported.',
            ];
        }

        // Asking for an access token is the only way to tell a live refresh token from a revoked one, both reading from the config as a plain string. The answer may come from the token cache, which only ever holds a token obtained within the hour - a weekly run outlives it
        try {
            $this->googleOAuthClient->getAccessToken();
        } catch (\Throwable $exception) {
            return [
                ...$row,
                'status' => HealthCheckResult::STATUS_ERROR,
                'summary' => 'Google refuses the stored connection, so the reviews are no longer imported: ' . $exception->getMessage(),
            ];
        }

        // Connected, but pointing at no listing: the token works and the sync still has nothing to read, the account and location ids being what names the fiche (see GoogleBusinessLocationResolver)
        if (!$this->googleBusinessProfileSource->isConfigured()) {
            return [
                ...$row,
                'status' => HealthCheckResult::STATUS_WARNING,
                'summary' => 'The Google connection works but no listing is selected, so there is nothing to import from.',
            ];
        }

        return [
            ...$row,
            'status' => HealthCheckResult::STATUS_OK,
            'summary' => 'The Google connection answers and the listing is selected.',
        ];
    }
}
