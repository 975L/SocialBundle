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
use c975L\SocialBundle\Controller\Management\SocialPinterestBoardController;
use c975L\SocialBundle\Service\PinterestClient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// The Pinterest tokens renew themselves nightly (see PinterestRefreshCommand): an access token past its end says the refresh stopped working, a refresh token past its end that the account has to connect again, a connection without a board that nothing is pinned. Read from the config only, no call to Pinterest
class PinterestHealthCheckProvider implements HealthCheckProviderInterface
{
    public const string KIND = 'social-pinterest';

    public function __construct(
        private readonly PinterestClient $pinterestClient,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getKind(): string
    {
        return self::KIND;
    }

    // No Pinterest app declared, or never connected: a site not pinning is no site whose pinning is broken
    public function runChecks(): array
    {
        $refreshExpiresAt = $this->pinterestClient->refreshExpiresAt();
        if (!$this->pinterestClient->isConfigured() || null === $refreshExpiresAt) {
            return [];
        }

        $expiresAt = $this->pinterestClient->expiresAt();
        $now = new \DateTimeImmutable();
        $row = [
            'url' => 'pinterest',
            'label' => 'Pinterest',
            'details' => ['expiresAt' => $expiresAt?->format(\DateTimeInterface::ATOM), 'refreshExpiresAt' => $refreshExpiresAt->format(\DateTimeInterface::ATOM)],
            'editUrl' => $this->urlGenerator->generate('social_pinterest_oauth_connect'),
        ];

        return [match (true) {
            $refreshExpiresAt <= $now => [...$row, 'status' => HealthCheckResult::STATUS_ERROR, 'summary' => 'The Pinterest connection ended on ' . $refreshExpiresAt->format('d/m/Y') . ': nothing is pinned until Pinterest is connected again.'],
            null === $expiresAt || $expiresAt <= $now => [...$row, 'status' => HealthCheckResult::STATUS_ERROR, 'summary' => 'The Pinterest access token is no longer renewed: is c975l:social:pinterest:refresh scheduled?'],
            !$this->pinterestClient->hasBoard() => [...$row, 'status' => HealthCheckResult::STATUS_WARNING, 'summary' => 'Pinterest is connected but no board is picked: nothing is pinned until one is.', 'editUrl' => $this->urlGenerator->generate(SocialPinterestBoardController::ROUTE)],
            default => [...$row, 'status' => HealthCheckResult::STATUS_OK, 'summary' => 'Pinterest is connected, its access token valid until ' . $expiresAt->format('d/m/Y') . '.'],
        }];
    }
}
