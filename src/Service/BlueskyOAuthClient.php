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
use c975L\ConfigBundle\Service\SiteUrlResolver;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

// The AT Protocol's OAuth, as a confidential client of the site's own: no app to register anywhere, the site publishing its metadata and public key itself (see BlueskyOAuthController). PAR, PKCE, private_key_jwt and DPoP are all mandatory there. The session - tokens and the DPoP key they are bound to - is kept in "social-bluesky-oauth-session", its refresh token lasting 180 days and renewed at every use
class BlueskyOAuthClient
{
    // Where an account is asked for when no handle is known: Bluesky's own entryway, which lets the owner pick the account on its page
    public const string ENTRYWAY = 'https://bsky.social';

    // Posting and uploading its image, nothing else - the owner reads this list on the consent page
    public const string SCOPE = 'atproto repo:app.bsky.feed.post?action=create blob:image/*';

    public const string KEY_SLUG = 'social-bluesky-oauth-key';

    public const string SESSION_SLUG = 'social-bluesky-oauth-session';

    // A token refreshed this many seconds before it expires, so it never runs out between the check and the call
    private const int EXPIRY_MARGIN = 60;

    // The DPoP nonces each server handed out, by origin: kept for the process, a stale one only costing a retry
    /** @var array<string, string> */
    private array $nonces = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ConfigServiceInterface $configService,
        private readonly ConfigValueWriter $configValueWriter,
        private readonly SiteUrlResolver $siteUrlResolver,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Es256Signer $signer,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function isConnected(): bool
    {
        return null !== $this->session();
    }

    // The account the site posts as, null before the connection
    public function getDid(): ?string
    {
        return $this->session()['did'] ?? null;
    }

    // What the site serves as its client id: the url of this very document
    /** @return array<string, mixed> */
    public function clientMetadata(): array
    {
        return [
            'client_id' => $this->clientId(),
            'client_name' => parse_url($this->siteUrl(), \PHP_URL_HOST),
            'client_uri' => $this->siteUrl(),
            'application_type' => 'web',
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'redirect_uris' => [$this->redirectUri()],
            'scope' => self::SCOPE,
            'token_endpoint_auth_method' => 'private_key_jwt',
            'token_endpoint_auth_signing_alg' => 'ES256',
            'dpop_bound_access_tokens' => true,
            'jwks_uri' => $this->siteUrl() . $this->urlGenerator->generate('social_bluesky_oauth_jwks'),
        ];
    }

    // The public half of the client key, the one its assertions are checked against
    /** @return array{keys: list<array<string, string>>} */
    public function jwks(): array
    {
        $jwk = $this->signer->publicJwk($this->clientKey());

        return ['keys' => [[...$jwk, 'kid' => Es256Signer::thumbprint($jwk), 'use' => 'sig', 'alg' => 'ES256']]];
    }

    // Bluesky reads the client from "site-url": an authorization started from another host would be signed with that host's key and sent back to "site-url"'s callback, so it can only fail
    public function isSiteHost(string $host): bool
    {
        return strtolower($host) === strtolower((string) parse_url($this->siteUrl(), \PHP_URL_HOST));
    }

    // The address the connection has to be started from
    public function siteUrl(): string
    {
        return $this->siteUrlResolver->siteUrl() ?? throw new \RuntimeException('"site-url" is not set: Bluesky needs the site\'s address to connect it.');
    }

    // Pushes the authorization request and answers the url to send the owner to, with what the callback needs kept in the session - the account the handle names, or the one picked on Bluesky's page when there is none
    /** @return array{url: string, pending: array<string, string>} */
    public function startAuthorization(?string $handle, string $state): array
    {
        $did = null;
        $issuer = self::ENTRYWAY;
        if (null !== $handle) {
            $did = $this->resolveHandle($handle);
            $issuer = $this->authorizationServer($this->pds($this->didDocument($did)));
        }

        $server = $this->serverMetadata($issuer);
        $verifier = Es256Signer::base64Url(random_bytes(32));
        $dpopKey = $this->signer->generateKey();

        $response = $this->send('POST', $server['pushed_authorization_request_endpoint'], $dpopKey, null, ['body' => [
            'client_id' => $this->clientId(),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'scope' => self::SCOPE,
            'state' => $state,
            'code_challenge' => Es256Signer::base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            ...(null === $handle ? [] : ['login_hint' => $handle]),
            ...$this->clientAssertion($issuer),
        ]]);
        $par = $this->data($response, 'the authorization request');

        return [
            'url' => $server['authorization_endpoint'] . '?' . http_build_query(['client_id' => $this->clientId(), 'request_uri' => $par['request_uri']]),
            'pending' => array_filter([
                'state' => $state,
                'verifier' => $verifier,
                'dpopKey' => $dpopKey,
                'issuer' => $issuer,
                'tokenEndpoint' => $server['token_endpoint'],
                'did' => $did,
            ], static fn (?string $value): bool => null !== $value),
        ];
    }

    // Exchanges the code for the tokens, checks they are the account's own server's, then keeps the session and the handle - nothing is written before everything checked out
    /** @param array<string, string> $pending */
    public function finishAuthorization(array $pending, string $code, string $issuer): void
    {
        if ($issuer !== $pending['issuer']) {
            throw new \RuntimeException('Bluesky answered from another authorization server than the one asked.');
        }

        $response = $this->send('POST', $pending['tokenEndpoint'], $pending['dpopKey'], null, ['body' => [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'code_verifier' => $pending['verifier'],
            'client_id' => $this->clientId(),
            ...$this->clientAssertion($pending['issuer']),
        ]]);
        $tokens = $this->data($response, 'the tokens');

        $did = (string) ($tokens['sub'] ?? '');
        if (!str_starts_with($did, 'did:') || (isset($pending['did']) && $did !== $pending['did'])) {
            throw new \RuntimeException('Bluesky connected another account than the one asked.');
        }

        // The account's own server has to name the very server that issued the tokens, or anyone's server could speak for it
        $document = $this->didDocument($did);
        $pds = $this->pds($document);
        if ($this->authorizationServer($pds) !== $pending['issuer']) {
            throw new \RuntimeException('The account\'s server does not trust the server that connected it.');
        }

        $session = [
            'did' => $did,
            'pds' => $pds,
            'issuer' => $pending['issuer'],
            'tokenEndpoint' => $pending['tokenEndpoint'],
            'dpopKey' => $pending['dpopKey'],
        ];
        $this->configValueWriter->write([
            self::SESSION_SLUG => (string) json_encode([...$session, ...$this->tokens($tokens)]),
            'social-bluesky-handle' => $this->handleOf($document) ?? $did,
        ]);
    }

    // A call to the account's own server, signed for the session - the access token refreshed first when it is about to expire
    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function call(string $method, array $options): array
    {
        $session = $this->freshSession($this->session() ?? throw new \RuntimeException('Bluesky is not connected.'));

        $response = $this->send('POST', $session['pds'] . '/xrpc/' . $method, $session['dpopKey'], (string) $session['accessToken'], $options);

        return $this->data($response, $method);
    }

    // The session with a live access token, refreshed with its single-use refresh token when due - under a lock and from a fresh read, since a worker running for an hour would otherwise replay a refresh token a web request already spent, and the new pair written at once
    /**
     * @param array<string, mixed> $session
     *
     * @return array<string, mixed>
     */
    private function freshSession(array $session): array
    {
        if ((int) $session['expiresAt'] > time() + self::EXPIRY_MARGIN) {
            return $session;
        }

        $lock = $this->lockFactory->createLock('social_bluesky_oauth_refresh');
        $lock->acquire(true);
        try {
            // Another process may have refreshed the pair while this one waited or kept its configs in memory
            $this->configService->invalidateCache();
            $session = $this->session() ?? throw new \RuntimeException('Bluesky is not connected.');
            if ((int) $session['expiresAt'] > time() + self::EXPIRY_MARGIN) {
                return $session;
            }

            $response = $this->send('POST', (string) $session['tokenEndpoint'], (string) $session['dpopKey'], null, ['body' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $session['refreshToken'],
                'client_id' => $this->clientId(),
                ...$this->clientAssertion((string) $session['issuer']),
            ]]);
            $session = [...$session, ...$this->tokens($this->data($response, 'the refreshed tokens'))];
            $this->configValueWriter->write([self::SESSION_SLUG => (string) json_encode($session)]);

            return $session;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<string, mixed> $tokens
     *
     * @return array{accessToken: string, refreshToken: string, expiresAt: int}
     */
    private function tokens(array $tokens): array
    {
        if (!isset($tokens['access_token'], $tokens['refresh_token'])) {
            throw new \RuntimeException('Bluesky handed no tokens back.');
        }

        return [
            'accessToken' => (string) $tokens['access_token'],
            'refreshToken' => (string) $tokens['refresh_token'],
            'expiresAt' => time() + (int) ($tokens['expires_in'] ?? 0),
        ];
    }

    // One request carrying its DPoP proof - sent again once with the nonce the server asks for, which every server does on the first request
    /** @param array<string, mixed> $options */
    private function send(string $method, string $url, string $dpopKey, ?string $accessToken, array $options): ResponseInterface
    {
        $response = $this->sendOnce($method, $url, $dpopKey, $accessToken, $options);

        return $this->asksForNonce($response) ? $this->sendOnce($method, $url, $dpopKey, $accessToken, $options) : $response;
    }

    // The nonce the server hands back is kept for the next proof to its origin
    /** @param array<string, mixed> $options */
    private function sendOnce(string $method, string $url, string $dpopKey, ?string $accessToken, array $options): ResponseInterface
    {
        $origin = (string) preg_replace('~^(https?://[^/]+).*$~', '$1', $url);
        $headers = [...$options['headers'] ?? [], 'DPoP' => $this->dpopProof($dpopKey, $method, $url, $this->nonces[$origin] ?? null, $accessToken)];
        if (null !== $accessToken) {
            $headers['Authorization'] = 'DPoP ' . $accessToken;
        }

        $response = $this->httpClient->request($method, $url, [...$options, 'headers' => $headers, 'timeout' => 30]);
        $nonce = $response->getHeaders(false)['dpop-nonce'][0] ?? null;
        if (null !== $nonce) {
            $this->nonces[$origin] = $nonce;
        }

        return $response;
    }

    // The authorization server says so in its body, the resource server in its WWW-Authenticate header
    private function asksForNonce(ResponseInterface $response): bool
    {
        if (!\in_array($response->getStatusCode(), [400, 401], true) || !isset($response->getHeaders(false)['dpop-nonce'])) {
            return false;
        }

        $challenge = implode(' ', $response->getHeaders(false)['www-authenticate'] ?? []);

        return str_contains($challenge, 'use_dpop_nonce') || 'use_dpop_nonce' === ($response->toArray(false)['error'] ?? null);
    }

    private function dpopProof(string $dpopKey, string $method, string $url, ?string $nonce, ?string $accessToken): string
    {
        return $this->signer->sign($dpopKey, ['typ' => 'dpop+jwt', 'jwk' => $this->signer->publicJwk($dpopKey)], array_filter([
            'jti' => bin2hex(random_bytes(16)),
            'htm' => $method,
            'htu' => strtok($url, '?#'),
            'iat' => time(),
            'nonce' => $nonce,
            'ath' => null === $accessToken ? null : Es256Signer::base64Url(hash('sha256', $accessToken, true)),
        ], static fn (mixed $value): bool => null !== $value));
    }

    // private_key_jwt: the client proves who it is with an assertion signed by the key its metadata publishes
    /** @return array{client_assertion_type: string, client_assertion: string} */
    private function clientAssertion(string $issuer): array
    {
        $key = $this->clientKey();

        return [
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $this->signer->sign($key, ['kid' => Es256Signer::thumbprint($this->signer->publicJwk($key))], [
                'iss' => $this->clientId(),
                'sub' => $this->clientId(),
                'aud' => $issuer,
                'jti' => bin2hex(random_bytes(16)),
                'iat' => time(),
            ]),
        ];
    }

    // The client key, generated the first time it is asked for and kept: changing it would break the session made with the old one
    private function clientKey(): string
    {
        $key = trim((string) $this->configService->get(self::KEY_SLUG));
        if ('' === $key) {
            $key = $this->signer->generateKey();
            $this->configValueWriter->write([self::KEY_SLUG => $key]);
        }

        return $key;
    }

    /** @return array<string, mixed>|null */
    private function session(): ?array
    {
        $session = json_decode((string) $this->configService->get(self::SESSION_SLUG), true);

        return \is_array($session) && isset($session['did'], $session['pds'], $session['dpopKey']) ? $session : null;
    }

    // A handle's DID, read from its DNS record first, then from its own web server, as the protocol resolves it
    private function resolveHandle(string $handle): string
    {
        $handle = strtolower(ltrim(trim($handle), '@'));
        foreach (@dns_get_record('_atproto.' . $handle, \DNS_TXT) ?: [] as $record) {
            if (str_starts_with((string) ($record['txt'] ?? ''), 'did=')) {
                return substr((string) $record['txt'], 4);
            }
        }

        $response = $this->httpClient->request('GET', 'https://' . $handle . '/.well-known/atproto-did', ['timeout' => 10]);
        $did = 200 === $response->getStatusCode() ? trim($response->getContent(false)) : '';
        if (!str_starts_with($did, 'did:')) {
            throw new \RuntimeException(sprintf('The Bluesky handle "%s" cannot be resolved.', $handle));
        }

        return $did;
    }

    /** @return array<string, mixed> */
    private function didDocument(string $did): array
    {
        $url = match (true) {
            str_starts_with($did, 'did:plc:') => 'https://plc.directory/' . $did,
            str_starts_with($did, 'did:web:') => 'https://' . substr($did, 8) . '/.well-known/did.json',
            default => throw new \RuntimeException(sprintf('The identifier "%s" is of no known kind.', $did)),
        };

        return $this->fetch($url);
    }

    // The account's own server, where its posts are written
    /** @param array<string, mixed> $document */
    private function pds(array $document): string
    {
        foreach ($document['service'] ?? [] as $service) {
            if (str_ends_with((string) ($service['id'] ?? ''), '#atproto_pds')) {
                return rtrim((string) $service['serviceEndpoint'], '/');
            }
        }

        throw new \RuntimeException('The account names no server of its own.');
    }

    /** @param array<string, mixed> $document */
    private function handleOf(array $document): ?string
    {
        foreach ($document['alsoKnownAs'] ?? [] as $alias) {
            if (str_starts_with((string) $alias, 'at://')) {
                return substr((string) $alias, 5);
            }
        }

        return null;
    }

    // The authorization server a PDS trusts
    private function authorizationServer(string $pds): string
    {
        $server = $this->fetch($pds . '/.well-known/oauth-protected-resource')['authorization_servers'][0] ?? null;

        return \is_string($server) ? rtrim($server, '/') : throw new \RuntimeException('The account\'s server names no authorization server.');
    }

    // The endpoints of an authorization server, which has to be the issuer it claims to be
    /** @return array{pushed_authorization_request_endpoint: string, authorization_endpoint: string, token_endpoint: string} */
    private function serverMetadata(string $issuer): array
    {
        $metadata = $this->fetch($issuer . '/.well-known/oauth-authorization-server');
        if (($metadata['issuer'] ?? null) !== $issuer || !isset($metadata['pushed_authorization_request_endpoint'], $metadata['authorization_endpoint'], $metadata['token_endpoint'])) {
            throw new \RuntimeException(sprintf('"%s" is no authorization server of the AT Protocol.', $issuer));
        }

        return $metadata;
    }

    /** @return array<string, mixed> */
    private function fetch(string $url): array
    {
        $response = $this->httpClient->request('GET', $url, ['timeout' => 10]);
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException(sprintf('%s answered %d.', $url, $response->getStatusCode()));
        }

        return $response->toArray(false);
    }

    // Bluesky explains a refusal in the body, which is what the screen should show rather than a bare status code
    /** @return array<string, mixed> */
    private function data(ResponseInterface $response, string $what): array
    {
        $data = $response->toArray(false);
        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException(sprintf('Bluesky refused %s: %s', $what, $data['error_description'] ?? $data['message'] ?? $data['error'] ?? $response->getStatusCode()));
        }

        return $data;
    }

    // Built on "site-url" rather than on the request: the scheduled posts refresh their token from a console, where the client id has to be the very same
    private function clientId(): string
    {
        return $this->siteUrl() . $this->urlGenerator->generate('social_bluesky_oauth_client_metadata');
    }

    private function redirectUri(): string
    {
        return $this->siteUrl() . $this->urlGenerator->generate('social_bluesky_oauth_callback');
    }
}
