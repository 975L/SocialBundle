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
use c975L\SocialBundle\Service\InstagramLoginClient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// The token of an Instagram connected with no Facebook Page renews itself nightly (see InstagramRefreshCommand), a month before its end: past its end the account has to connect again, closer than the window's last days the renewal stopped working. Read from the config only, no call to Instagram
class InstagramHealthCheckProvider implements HealthCheckProviderInterface
{
    public const string KIND = 'social-instagram';

    // Fewer days left than this, the nightly renewal has had three weeks to run and did not
    private const string STOPPED_BEFORE = '+7 days';

    public function __construct(
        private readonly InstagramLoginClient $instagramLoginClient,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getKind(): string
    {
        return self::KIND;
    }

    // No app declared, never connected or its token emptied to go back to the Page: a site whose Instagram goes through its Page, or has none, is not broken here
    public function runChecks(): array
    {
        $expiresAt = $this->instagramLoginClient->expiresAt();
        if (!$this->instagramLoginClient->isConfigured() || null === $expiresAt || '' === $this->instagramLoginClient->accessToken()) {
            return [];
        }

        $row = [
            'url' => 'instagram',
            'label' => 'Instagram',
            'details' => ['expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM)],
            'editUrl' => $this->urlGenerator->generate('social_instagram_oauth_connect'),
        ];

        return [match (true) {
            $expiresAt <= new \DateTimeImmutable() => [...$row, 'status' => HealthCheckResult::STATUS_ERROR, 'summary' => 'The Instagram connection ended on ' . $expiresAt->format('d/m/Y') . ': until Instagram is connected again, the posts go to the account of the Page, if any.'],
            $expiresAt <= new \DateTimeImmutable(self::STOPPED_BEFORE) => [...$row, 'status' => HealthCheckResult::STATUS_WARNING, 'summary' => 'The Instagram token ends on ' . $expiresAt->format('d/m/Y') . ' and is no longer renewed: is c975l:social:instagram:refresh scheduled?'],
            default => [...$row, 'status' => HealthCheckResult::STATUS_OK, 'summary' => 'Instagram is connected, its token valid until ' . $expiresAt->format('d/m/Y') . '.'],
        }];
    }
}
