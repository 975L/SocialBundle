<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

// Finds which listing the account that just consented holds, so the connection stores the two ids itself rather than asking an owner to hunt them down in Google's own back office
class GoogleBusinessLocationResolver
{
    private const string ACCOUNTS_ENDPOINT = 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts';

    private const string LOCATIONS_ENDPOINT = 'https://mybusinessbusinessinformation.googleapis.com/v1/accounts/%s/locations';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly GoogleOAuthClient $googleOAuthClient,
    ) {
    }

    // The first listing of the first account, which is what a site owning one listing has. An owner holding several edits the two configs by hand afterwards - a picker for a case most sites never meet would be a screen built for nobody
    /**
     * @return array{accountId: string, locationId: string}
     */
    public function resolveFirst(): array
    {
        $accounts = $this->request(self::ACCOUNTS_ENDPOINT)['accounts'] ?? [];
        $accountId = $this->firstId($accounts, 'accounts/');

        if (null === $accountId) {
            throw new \RuntimeException('The connected Google account manages no Business Profile account.');
        }

        $locations = $this->request(sprintf(self::LOCATIONS_ENDPOINT, $accountId), ['readMask' => 'name'])['locations'] ?? [];
        $locationId = $this->firstId($locations, 'locations/');

        if (null === $locationId) {
            throw new \RuntimeException(sprintf('The Google account "%s" holds no business listing.', $accountId));
        }

        return ['accountId' => $accountId, 'locationId' => $locationId];
    }

    // Google names a resource "accounts/123" or "locations/456"; only the id itself is stored, the path being rebuilt where it is needed
    private function firstId(mixed $resources, string $prefix): ?string
    {
        if (!is_array($resources)) {
            return null;
        }

        foreach ($resources as $resource) {
            $name = is_array($resource) ? ($resource['name'] ?? null) : null;

            if (is_string($name) && str_starts_with($name, $prefix)) {
                return substr($name, strlen($prefix));
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private function request(string $url, array $query = []): array
    {
        $response = $this->httpClient->request('GET', $url, [
            'auth_bearer' => $this->googleOAuthClient->getAccessToken(),
            'query' => $query,
            'timeout' => 30,
        ]);

        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException(sprintf('Google answered %d on %s: %s', $response->getStatusCode(), $url, $response->getContent(false)));
        }

        $payload = json_decode($response->getContent(false), true);

        return is_array($payload) ? $payload : [];
    }
}
