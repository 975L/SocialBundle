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
use c975L\SocialBundle\Service\TikTokClient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// The TikTok tokens renew themselves nightly (see TikTokRefreshCommand): an access token over for more than a day and a half says the refresh stopped working, a refresh token past its end that the account has to connect again. Read from the config only, no call to TikTok
class TikTokHealthCheckProvider implements HealthCheckProviderInterface
{
    use SkippedRowTrait;

    public const string KIND = 'social-tiktok';

    public function __construct(
        private readonly TikTokClient $tikTokClient,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getKind(): string
    {
        return self::KIND;
    }

    // No TikTok app declared, or never connected: a site not posting there is no site whose posting is broken
    public function runChecks(): array
    {
        $refreshExpiresAt = $this->tikTokClient->refreshExpiresAt();
        if (!$this->tikTokClient->isConfigured() || null === $refreshExpiresAt) {
            return $this->skipped('tiktok', 'TikTok', 'TikTok is not connected.');
        }

        $expiresAt = $this->tikTokClient->expiresAt();
        $now = new \DateTimeImmutable();
        $row = [
            'url' => 'tiktok',
            'label' => 'TikTok',
            'details' => ['expiresAt' => $expiresAt?->format(\DateTimeInterface::ATOM), 'refreshExpiresAt' => $refreshExpiresAt->format(\DateTimeInterface::ATOM)],
            'editUrl' => $this->urlGenerator->generate('social_tiktok_oauth_connect'),
        ];

        // A token lasting 2 hours is over for most of each day, up to 22 hours before the next night: only one over well past that says the nightly refresh stopped
        return [match (true) {
            $refreshExpiresAt <= $now => [...$row, 'status' => HealthCheckResult::STATUS_ERROR, 'summary' => 'The TikTok connection ended on ' . $refreshExpiresAt->format('d/m/Y') . ': nothing is posted until TikTok is connected again.'],
            null === $expiresAt || $expiresAt <= $now->modify('-36 hours') => [...$row, 'status' => HealthCheckResult::STATUS_ERROR, 'summary' => 'The TikTok access token is no longer renewed: is c975l:social:tiktok:refresh scheduled?'],
            default => [...$row, 'status' => HealthCheckResult::STATUS_OK, 'summary' => 'TikTok is connected until ' . $refreshExpiresAt->format('d/m/Y') . ', its access token renewed every night.'],
        }];
    }
}
