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
use c975L\SocialBundle\Service\LinkedInClient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// The LinkedIn token ends on its own after 60 days, LinkedIn handing a self-serve app no refresh token: the posts then fail one after the other. Said a fortnight ahead, while reconnecting is one click, and again once it is too late. Read from the config only, no call to LinkedIn
class LinkedInHealthCheckProvider implements HealthCheckProviderInterface
{
    public const string KIND = 'social-linkedin';

    // How long before the end the owner is told to connect again
    private const string NOTICE = '+14 days';

    public function __construct(
        private readonly LinkedInClient $linkedInClient,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getKind(): string
    {
        return self::KIND;
    }

    // No LinkedIn app declared, or never connected: a site not posting there is no site whose posting is broken
    public function runChecks(): array
    {
        $expiresAt = $this->linkedInClient->expiresAt();
        if (!$this->linkedInClient->isConfigured() || null === $expiresAt) {
            return [];
        }

        $row = [
            'url' => 'linkedin',
            'label' => 'LinkedIn',
            'details' => ['expiresAt' => $expiresAt->format(\DateTimeInterface::ATOM)],
            'editUrl' => $this->urlGenerator->generate('social_linkedin_oauth_connect'),
        ];

        return [match (true) {
            $expiresAt <= new \DateTimeImmutable() => [...$row, 'status' => HealthCheckResult::STATUS_ERROR, 'summary' => 'The LinkedIn token expired on ' . $expiresAt->format('d/m/Y') . ': nothing goes out there until the profile is connected again.'],
            $expiresAt <= new \DateTimeImmutable(self::NOTICE) => [...$row, 'status' => HealthCheckResult::STATUS_WARNING, 'summary' => 'The LinkedIn token expires on ' . $expiresAt->format('d/m/Y') . ': connect the profile again before then.'],
            default => [...$row, 'status' => HealthCheckResult::STATUS_OK, 'summary' => 'The LinkedIn token is valid until ' . $expiresAt->format('d/m/Y') . '.'],
        }];
    }
}
