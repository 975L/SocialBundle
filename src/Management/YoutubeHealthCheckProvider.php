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
use c975L\SocialBundle\Service\YoutubeClient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// Whether the YouTube channel's refresh token still works, revoked by a password change or ended after seven days while the Google Cloud project is in testing, every planned video then failing. Run from c975l:health-check:run only, as it goes to Google's token endpoint
class YoutubeHealthCheckProvider implements HealthCheckProviderInterface
{
    public const string KIND = 'social-youtube';

    public function __construct(
        private readonly YoutubeClient $youtubeClient,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getKind(): string
    {
        return self::KIND;
    }

    // No Google application, or no channel connected: a site not posting on YouTube is no site whose posting is broken
    public function runChecks(): array
    {
        if (!$this->youtubeClient->isConfigured() || !$this->youtubeClient->isConnected()) {
            return [];
        }

        $row = [
            'url' => 'youtube',
            'label' => 'YouTube',
            'details' => [],
            'editUrl' => $this->urlGenerator->generate('social_youtube_oauth_connect'),
        ];

        // Asking for an access token is the only way to tell a live refresh token from a revoked one
        try {
            $this->youtubeClient->getAccessToken();
        } catch (\Throwable $exception) {
            return [[...$row, 'status' => HealthCheckResult::STATUS_ERROR, 'summary' => 'Google refuses the stored YouTube connection, so no video goes out until the channel is connected again: ' . $exception->getMessage()]];
        }

        return [[...$row, 'status' => HealthCheckResult::STATUS_OK, 'summary' => 'The YouTube connection answers.']];
    }
}
