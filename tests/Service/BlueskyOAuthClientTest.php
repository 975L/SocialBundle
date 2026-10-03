<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\SiteUrlResolver;
use c975L\SocialBundle\Service\BlueskyOAuthClient;
use c975L\SocialBundle\Service\ConfigValueWriter;
use c975L\SocialBundle\Service\Es256Signer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// Against a Bluesky played by MockHttpClient: the entryway bsky.social, a PDS trusting it, and plc.directory - each server asking for a DPoP nonce on its first request, as the real ones do
class BlueskyOAuthClientTest extends TestCase
{
    /** @var array<string, ?string> */
    private array $config = ['site-url' => 'https://site.example'];

    // What another process writes meanwhile, seen once the configs cache is invalidated
    /** @var array<string, ?string> */
    private array $externalConfig = [];

    /** @var list<array<string, string|null>> */
    private array $written = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    private array $requests = [];

    private string $pdsAuthorizationServer = 'https://bsky.social';

    private function createClient(): BlueskyOAuthClient
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(fn (string $slug): ?string => $this->config[$slug] ?? null);
        $configService->method('invalidateCache')->willReturnCallback(function (): void {
            $this->config = [...$this->config, ...$this->externalConfig];
        });

        $writer = $this->createStub(ConfigValueWriter::class);
        $writer->method('write')->willReturnCallback(function (array $values): void {
            $this->written[] = $values;
            $this->config = [...$this->config, ...$values];
        });

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(static fn (string $route): string => '/' . $route);

        return new BlueskyOAuthClient(new MockHttpClient($this->respond(...)), $configService, $writer, new SiteUrlResolver($configService), $urlGenerator, new Es256Signer(), new LockFactory(new InMemoryStore()));
    }

    /** @param array<string, mixed> $options */
    private function respond(string $method, string $url, array $options): MockResponse
    {
        $headers = [];
        foreach ($options['headers'] ?? [] as $header) {
            [$name, $value] = explode(': ', $header, 2);
            $headers[strtolower($name)] = $value;
        }
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => (string) ($options['body'] ?? '')];
        $nonce = $this->dpopClaims($headers['dpop'] ?? null)['nonce'] ?? null;

        return match (true) {
            'https://bsky.social/.well-known/oauth-authorization-server' === $url => $this->json([
                'issuer' => 'https://bsky.social',
                'pushed_authorization_request_endpoint' => 'https://bsky.social/oauth/par',
                'authorization_endpoint' => 'https://bsky.social/oauth/authorize',
                'token_endpoint' => 'https://bsky.social/oauth/token',
            ]),
            'https://plc.directory/did:plc:abc' === $url => $this->json([
                'id' => 'did:plc:abc',
                'alsoKnownAs' => ['at://me.bsky.social'],
                'service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example']],
            ]),
            'https://pds.example/.well-known/oauth-protected-resource' === $url => $this->json(['authorization_servers' => [$this->pdsAuthorizationServer]]),
            // The authorization server asks for its nonce in the body, the PDS in its WWW-Authenticate header
            str_starts_with($url, 'https://bsky.social/oauth/') && 'n-as' !== $nonce => $this->json(['error' => 'use_dpop_nonce'], 400, ['DPoP-Nonce' => 'n-as']),
            str_starts_with($url, 'https://pds.example/xrpc/') && 'n-pds' !== $nonce => $this->json(['error' => 'use_dpop_nonce'], 401, ['DPoP-Nonce' => 'n-pds', 'WWW-Authenticate' => 'DPoP error="use_dpop_nonce"']),
            'https://bsky.social/oauth/par' === $url => $this->json(['request_uri' => 'urn:ietf:params:oauth:request_uri:req-1', 'expires_in' => 300], 201),
            'https://bsky.social/oauth/token' === $url => $this->json(['access_token' => 'access-' . \count($this->requests), 'refresh_token' => 'refresh-' . \count($this->requests), 'expires_in' => 1800, 'sub' => 'did:plc:abc', 'token_type' => 'DPoP']),
            'https://pds.example/xrpc/com.atproto.repo.createRecord' === $url => $this->json(['uri' => 'at://did:plc:abc/app.bsky.feed.post/3k']),
            default => new MockResponse('', ['http_code' => 404]),
        };
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    private function json(array $data, int $status = 200, array $headers = []): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => $status, 'response_headers' => $headers]);
    }

    /** @return array<string, mixed> */
    private function dpopClaims(?string $proof): array
    {
        return null === $proof ? [] : (array) json_decode((string) base64_decode(strtr(explode('.', $proof)[1], '-_', '+/')), true);
    }

    /**
     * @return array<string, string>
     */
    private function form(string $body): array
    {
        parse_str($body, $form);

        return array_map(strval(...), $form);
    }

    /** @return array<string, string> */
    private function pending(): array
    {
        return ['state' => 's', 'verifier' => 'v', 'dpopKey' => new Es256Signer()->generateKey(), 'issuer' => 'https://bsky.social', 'tokenEndpoint' => 'https://bsky.social/oauth/token'];
    }

    // The client id is the url of this very document, whose key is generated once and kept
    public function testTheMetadataNameTheSiteAsAConfidentialClient(): void
    {
        $client = $this->createClient();
        $metadata = $client->clientMetadata();

        $this->assertSame('https://site.example/social_bluesky_oauth_client_metadata', $metadata['client_id']);
        $this->assertSame(['https://site.example/social_bluesky_oauth_callback'], $metadata['redirect_uris']);
        $this->assertSame('private_key_jwt', $metadata['token_endpoint_auth_method']);
        $this->assertTrue($metadata['dpop_bound_access_tokens']);
        $this->assertSame(BlueskyOAuthClient::SCOPE, $metadata['scope']);

        $client->jwks();
        $client->jwks();
        $this->assertCount(1, $this->written);
        // The key id is the key's own thumbprint, so a key of another site is told apart from this one
        $jwk = $client->jwks()['keys'][0];
        $this->assertSame(Es256Signer::thumbprint($jwk), $jwk['kid']);
    }

    // Bluesky knows the site by "site-url" alone, whatever the case of the host the request came on
    public function testOnlyTheSiteUrlHostCanStartTheConnection(): void
    {
        $client = $this->createClient();

        $this->assertTrue($client->isSiteHost('Site.Example'));
        $this->assertFalse($client->isSiteHost('127.0.0.1'));
    }

    // No handle known: Bluesky's entryway, the owner picking the account there. The request is pushed again with the nonce the server asked for
    public function testTheAuthorizationIsPushedThenTheOwnerSentToBluesky(): void
    {
        $authorization = $this->createClient()->startAuthorization(null, 'state-1');

        $this->assertSame('https://bsky.social/oauth/authorize?client_id=' . urlencode('https://site.example/social_bluesky_oauth_client_metadata') . '&request_uri=' . urlencode('urn:ietf:params:oauth:request_uri:req-1'), $authorization['url']);
        $this->assertSame('https://bsky.social', $authorization['pending']['issuer']);
        $this->assertArrayNotHasKey('did', $authorization['pending']);

        $pushes = array_values(array_filter($this->requests, static fn (array $request): bool => 'https://bsky.social/oauth/par' === $request['url']));
        $this->assertCount(2, $pushes);
        $this->assertSame('n-as', $this->dpopClaims($pushes[1]['headers']['dpop'])['nonce']);
        $form = $this->form($pushes[1]['body']);
        $this->assertSame('state-1', $form['state']);
        $this->assertSame('S256', $form['code_challenge_method']);
        $this->assertSame(BlueskyOAuthClient::SCOPE, $form['scope']);
        $this->assertSame('urn:ietf:params:oauth:client-assertion-type:jwt-bearer', $form['client_assertion_type']);
    }

    public function testTheConnectionKeepsTheSessionAndTheHandle(): void
    {
        $this->createClient()->finishAuthorization($this->pending(), 'code-1', 'https://bsky.social');

        $values = end($this->written);
        $this->assertSame('me.bsky.social', $values['social-bluesky-handle']);
        $session = json_decode((string) $values[BlueskyOAuthClient::SESSION_SLUG], true);
        $this->assertSame('did:plc:abc', $session['did']);
        $this->assertSame('https://pds.example', $session['pds']);
        $this->assertStringStartsWith('access-', $session['accessToken']);
        $this->assertTrue($this->createClient()->isConnected());
    }

    // The callback's issuer has to be the server the request was pushed to
    public function testAnotherIssuerIsRefused(): void
    {
        $this->expectExceptionMessage('another authorization server');

        $this->createClient()->finishAuthorization($this->pending(), 'code-1', 'https://evil.example');
    }

    // A PDS trusting another server: the tokens do not speak for that account
    public function testAnAccountWhoseServerTrustsAnotherIssuerIsRefused(): void
    {
        $this->pdsAuthorizationServer = 'https://other.example';
        $this->expectExceptionMessage('does not trust');

        $this->createClient()->finishAuthorization($this->pending(), 'code-1', 'https://bsky.social');
    }

    // Expired, the access token is refreshed first and the new pair kept, the call then carrying the token and its hash in the proof
    public function testAnExpiredTokenIsRefreshedBeforeTheCall(): void
    {
        $this->config[BlueskyOAuthClient::SESSION_SLUG] = (string) json_encode([...$this->pending(), 'did' => 'did:plc:abc', 'pds' => 'https://pds.example', 'accessToken' => 'old', 'refreshToken' => 'refresh-old', 'expiresAt' => time() - 10]);

        $created = $this->createClient()->call('com.atproto.repo.createRecord', ['json' => ['repo' => 'did:plc:abc']]);

        $this->assertSame('at://did:plc:abc/app.bsky.feed.post/3k', $created['uri']);
        $refresh = $this->form(array_values(array_filter($this->requests, static fn (array $request): bool => 'https://bsky.social/oauth/token' === $request['url']))[1]['body']);
        $this->assertSame(['refresh_token', 'refresh-old'], [$refresh['grant_type'], $refresh['refresh_token']]);

        $session = json_decode((string) $this->config[BlueskyOAuthClient::SESSION_SLUG], true);
        $call = end($this->requests);
        $this->assertSame('DPoP ' . $session['accessToken'], $call['headers']['authorization']);
        $this->assertSame(Es256Signer::base64Url(hash('sha256', $session['accessToken'], true)), $this->dpopClaims($call['headers']['dpop'])['ath']);
        $this->assertSame('n-pds', $this->dpopClaims($call['headers']['dpop'])['nonce']);
    }

    // A pair another process already refreshed is taken as is: the spent refresh token is never replayed
    public function testAPairRefreshedByAnotherProcessIsNotRefreshedAgain(): void
    {
        $session = [...$this->pending(), 'did' => 'did:plc:abc', 'pds' => 'https://pds.example', 'accessToken' => 'old', 'refreshToken' => 'refresh-old', 'expiresAt' => time() - 10];
        $this->config[BlueskyOAuthClient::SESSION_SLUG] = (string) json_encode($session);
        $this->externalConfig[BlueskyOAuthClient::SESSION_SLUG] = (string) json_encode([...$session, 'accessToken' => 'fresh', 'refreshToken' => 'refresh-fresh', 'expiresAt' => time() + 1800]);

        $this->createClient()->call('com.atproto.repo.createRecord', ['json' => ['repo' => 'did:plc:abc']]);

        $this->assertSame([], array_filter($this->requests, static fn (array $request): bool => 'https://bsky.social/oauth/token' === $request['url']));
        $this->assertSame('DPoP fresh', end($this->requests)['headers']['authorization']);
    }
}
