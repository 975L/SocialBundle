<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// The v4 host of the Google listing the site was connected to, shared by its reviews (GoogleBusinessProfileSource) and its posts (GoogleBusinessPublisher): the reviews and local posts endpoints were never moved to the split Business Profile APIs, and are only reachable once the Cloud project has been allowlisted
class GoogleBusinessClient
{
    private const string API_BASE = 'https://mybusiness.googleapis.com/v4';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly GoogleOAuthClient $googleOAuthClient,
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    // The Google application declared and a listing resolved by the connection
    public function isConfigured(): bool
    {
        return $this->googleOAuthClient->isConfigured()
            && null !== $this->accountId()
            && null !== $this->locationId();
    }

    // The listing's own path, under which its reviews and its posts live
    public function locationPath(): string
    {
        return sprintf('/accounts/%s/locations/%s', (string) $this->accountId(), (string) $this->locationId());
    }

    // The json object Google answers, an empty one for a DELETE's empty body - throws with Google's own words on a refusal
    /**
     * @param array<string, mixed>      $query
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $response = $this->httpClient->request($method, self::API_BASE . $path, [
            'auth_bearer' => $this->googleOAuthClient->getAccessToken(),
            'query' => $query,
            ...(null !== $body ? ['json' => $body] : []),
            'timeout' => 30,
        ]);

        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException(sprintf('Google answered %d on %s: %s', $response->getStatusCode(), $path, $response->getContent(false)));
        }

        // A DELETE answers with an empty body, which is a success and not an unreadable payload
        $content = $response->getContent(false);

        if ('' === trim($content)) {
            return [];
        }

        $payload = json_decode($content, true);

        if (!is_array($payload)) {
            throw new \RuntimeException(sprintf('Google answered %s with something other than a json object.', $path));
        }

        return $payload;
    }

    private function accountId(): ?string
    {
        $value = $this->configService->get('social-google-business-account-id');

        return is_string($value) && '' !== $value ? $value : null;
    }

    private function locationId(): ?string
    {
        $value = $this->configService->get('social-google-business-location-id');

        return is_string($value) && '' !== $value ? $value : null;
    }
}
