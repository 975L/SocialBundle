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
use c975L\SocialBundle\Service\ProductionGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
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

    // The nonce the account's server asks for, which it changes as time goes
    private string $pdsNonce = 'n-pds';

    // The account the tokens are issued for, and the server its did:web document names
    private string $sub = 'did:plc:abc';

    private string $webServiceEndpoint = 'https://pds.example';

    /** @var string|list<string> */
    private string | array $parEndpoint = 'https://bsky.social/oauth/par';

    // What the authorization server says when it turns the request down, null when it takes it
    private ?string $parRefusal = null;

    // The addresses the hosts resolve to, so the guard against private networks never asks the DNS
    private const array RESOLVE = [
        'bsky.social' => '93.184.216.34',
        'plc.directory' => '93.184.216.34',
        'pds.example' => '93.184.216.34',
        'web.example' => '93.184.216.34',
        'internal.example' => '10.0.0.1',
    ];

    private function createClient(string $environment = 'prod', bool $resolved = true): BlueskyOAuthClient
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

        // Left unresolved, the client is the raw one the container hands over, which BlueskyOAuthClient guards itself
        $httpClient = new MockHttpClient($this->respond(...));
        if ($resolved) {
            $httpClient = new NoPrivateNetworkHttpClient($httpClient)->withOptions(['resolve' => self::RESOLVE]);
        }

        return new BlueskyOAuthClient($httpClient, $configService, $writer, new SiteUrlResolver($configService), $urlGenerator, new Es256Signer(), new LockFactory(new InMemoryStore()), new ProductionGuard($environment));
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
                'pushed_authorization_request_endpoint' => $this->parEndpoint,
                'authorization_endpoint' => 'https://bsky.social/oauth/authorize',
                'token_endpoint' => 'https://bsky.social/oauth/token',
            ]),
            'https://plc.directory/did:plc:abc' === $url => $this->json([
                'id' => 'did:plc:abc',
                'alsoKnownAs' => ['at://me.bsky.social'],
                'service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example']],
            ]),
            'https://web.example/.well-known/did.json' === $url => $this->json([
                'id' => 'did:web:web.example',
                'service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => $this->webServiceEndpoint]],
            ]),
            'https://pds.example/.well-known/oauth-protected-resource' === $url => $this->json(['authorization_servers' => [$this->pdsAuthorizationServer]]),
            // The authorization server asks for its nonce in the body, the PDS in its WWW-Authenticate header
            str_starts_with($url, 'https://bsky.social/oauth/') && 'n-as' !== $nonce => $this->json(['error' => 'use_dpop_nonce'], 400, ['DPoP-Nonce' => 'n-as']),
            str_starts_with($url, 'https://pds.example/xrpc/') && $this->pdsNonce !== $nonce => $this->json(['error' => 'use_dpop_nonce'], 401, ['DPoP-Nonce' => $this->pdsNonce, 'WWW-Authenticate' => 'DPoP error="use_dpop_nonce"']),
            'https://bsky.social/oauth/par' === $url && null !== $this->parRefusal => $this->json(['error' => 'invalid_request', 'error_description' => $this->parRefusal], 400),
            'https://bsky.social/oauth/par' === $url => $this->json(['request_uri' => 'urn:ietf:params:oauth:request_uri:req-1', 'expires_in' => 300], 201),
            // A scope of its own syntax, as a server without granular permissions answers - the site has to keep what it asked for
            'https://bsky.social/oauth/token' === $url => $this->json(['access_token' => 'access-' . \count($this->requests), 'refresh_token' => 'refresh-' . \count($this->requests), 'expires_in' => 1800, 'scope' => 'atproto', 'sub' => $this->sub, 'token_type' => 'DPoP']),
            'https://pds.example/xrpc/com.atproto.repo.createRecord' === $url => $this->json(['uri' => 'at://did:plc:abc/app.bsky.feed.post/3k']),
            'https://pds.example/xrpc/com.atproto.repo.uploadBlob' === $url => $this->json(['blob' => ['$type' => 'blob']]),
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
        $this->assertSame(BlueskyOAuthClient::SCOPE, $session['scope']);
        $this->assertTrue($this->createClient()->isConnected());
        $this->assertFalse($this->createClient()->needsReconnection());
    }

    // A session granted fewer permissions than the base scope, or from before the scope was kept, has to be connected again - told once, the mark kept beside the tokens
    public function testASessionWithAnOlderScopeNeedsReconnection(): void
    {
        $session = [...$this->pending(), 'did' => 'did:plc:abc', 'pds' => 'https://pds.example', 'accessToken' => 'a', 'refreshToken' => 'r', 'expiresAt' => time() + 1800];
        $this->config[BlueskyOAuthClient::SESSION_SLUG] = (string) json_encode($session);
        $this->assertTrue($this->createClient()->needsReconnection());

        $this->config[BlueskyOAuthClient::SESSION_SLUG] = (string) json_encode([...$session, 'scope' => 'atproto repo:app.bsky.feed.post?action=create blob:image/*']);
        $client = $this->createClient();
        $this->assertTrue($client->needsReconnection());
        $this->assertFalse($client->isReconnectionNotified());

        $client->markReconnectionNotified();
        $this->assertTrue($client->isReconnectionNotified());
        $this->assertSame('r', json_decode((string) $this->config[BlueskyOAuthClient::SESSION_SLUG], true)['refreshToken']);
    }

    // Told before reposting came to be, under the same base scope: not told again
    public function testAReminderAlreadySentStaysValidOnceRepostingIsAsked(): void
    {
        $session = [...$this->pending(), 'did' => 'did:plc:abc', 'pds' => 'https://pds.example', 'accessToken' => 'a', 'refreshToken' => 'r', 'expiresAt' => time() + 1800];
        $this->config[BlueskyOAuthClient::SESSION_SLUG] = (string) json_encode([...$session, 'notifiedScope' => 'atproto repo:app.bsky.feed.post?action=create blob:image/* blob:video/*']);

        $this->assertTrue($this->createClient()->isReconnectionNotified());
    }

    // A session from before reposting stays connected, only unable to repost until a new consent
    public function testASessionWithoutRepostStaysConnected(): void
    {
        $session = [...$this->pending(), 'did' => 'did:plc:abc', 'pds' => 'https://pds.example', 'accessToken' => 'a', 'refreshToken' => 'r', 'expiresAt' => time() + 1800];
        $this->config[BlueskyOAuthClient::SESSION_SLUG] = (string) json_encode([...$session, 'scope' => 'atproto repo:app.bsky.feed.post?action=create blob:image/* blob:video/*']);
        $client = $this->createClient();
        $this->assertFalse($client->needsReconnection());
        $this->assertFalse($client->canRepost());

        $this->config[BlueskyOAuthClient::SESSION_SLUG] = (string) json_encode([...$session, 'scope' => BlueskyOAuthClient::SCOPE]);
        $this->assertTrue($this->createClient()->canRepost());
    }

    // Disconnected between the check and the mark: nothing left to mark, nor to remind of
    public function testMarkingADisconnectedSessionDoesNothing(): void
    {
        $this->createClient()->markReconnectionNotified();

        $this->assertSame([], $this->written);
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

    // Outside production the single-use refresh token is never spent: production's connection would end with it
    public function testNoRefreshOutsideProduction(): void
    {
        $this->config[BlueskyOAuthClient::SESSION_SLUG] = (string) json_encode([...$this->pending(), 'did' => 'did:plc:abc', 'pds' => 'https://pds.example', 'accessToken' => 'old', 'refreshToken' => 'refresh-old', 'expiresAt' => time() - 10]);

        $this->expectExceptionMessage('The Bluesky tokens are only renewed in production.');

        try {
            $this->createClient('dev')->call('com.atproto.repo.createRecord', ['json' => ['repo' => 'did:plc:abc']]);
        } finally {
            $this->assertSame([], $this->requests);
        }
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

    // A blob never travels before the server's nonce of the moment is known: a bodiless request reads it before each upload, which then goes once, signed with it - the nonce kept from an earlier call having expired meanwhile
    public function testABlobWaitsForTheServerNonce(): void
    {
        $this->config[BlueskyOAuthClient::SESSION_SLUG] = (string) json_encode([...$this->pending(), 'did' => 'did:plc:abc', 'pds' => 'https://pds.example', 'accessToken' => 'fresh', 'refreshToken' => 'refresh-fresh', 'expiresAt' => time() + 1800]);
        $client = $this->createClient();

        $client->call('com.atproto.repo.uploadBlob', ['headers' => ['Content-Type' => 'image/webp'], 'body' => 'bytes']);
        $this->pdsNonce = 'n-pds-later';
        $client->call('com.atproto.repo.uploadBlob', ['headers' => ['Content-Type' => 'image/webp'], 'body' => 'bytes']);

        $this->assertCount(4, $this->requests);
        foreach (['n-pds', 'n-pds-later'] as $call => $nonce) {
            [$read, $upload] = \array_slice($this->requests, 2 * $call, 2);
            $this->assertSame(['GET', 'https://pds.example/xrpc/com.atproto.server.getSession', ''], [$read['method'], $read['url'], $read['body']]);
            $this->assertSame(['https://pds.example/xrpc/com.atproto.repo.uploadBlob', 'bytes'], [$upload['url'], $upload['body']]);
            $this->assertSame($nonce, $this->dpopClaims($upload['headers']['dpop'])['nonce']);
        }
    }

    // A handle is a domain name: anything else could make another address of it, and is refused before any request
    public function testAHandleThatIsNoDomainNameIsRefused(): void
    {
        $this->expectExceptionMessage('is no domain name');

        try {
            $this->createClient()->startAuthorization('web.example/x@127.0.0.1', 'state-1');
        } finally {
            $this->assertSame([], $this->requests);
        }
    }

    // A did:web on an address rather than a domain name is of no kind the protocol knows
    public function testADidWebOnAnAddressIsRefused(): void
    {
        $this->sub = 'did:web:127.0.0.1';
        $this->expectExceptionMessage('of no known kind');

        $this->createClient()->finishAuthorization($this->pending(), 'code-1', 'https://bsky.social');
    }

    // A did:web document naming its server over plain http is not followed
    public function testAServerOutsideHttpsIsRefused(): void
    {
        $this->sub = 'did:web:web.example';
        $this->webServiceEndpoint = 'http://pds.example';
        $this->expectExceptionMessage('is not served over https');

        $this->createClient()->finishAuthorization($this->pending(), 'code-1', 'https://bsky.social');
    }

    // A did:web document naming a server of the private network is never asked
    public function testAServerOnThePrivateNetworkIsNeverAsked(): void
    {
        $this->sub = 'did:web:web.example';
        $this->webServiceEndpoint = 'https://internal.example';
        $this->expectException(TransportException::class);

        try {
            $this->createClient()->finishAuthorization($this->pending(), 'code-1', 'https://bsky.social');
        } finally {
            $this->assertSame([], array_filter($this->requests, static fn (array $request): bool => str_contains($request['url'], 'internal.example')));
        }
    }

    public function testAnAuthorizationServerOutsideHttpsIsRefused(): void
    {
        $this->pdsAuthorizationServer = 'http://bsky.social';
        $this->expectExceptionMessage('is not served over https');

        $this->createClient()->finishAuthorization($this->pending(), 'code-1', 'https://bsky.social');
    }

    // The owner is never sent, nor the request pushed, to an endpoint outside https
    public function testAnEndpointOutsideHttpsIsRefused(): void
    {
        $this->parEndpoint = 'http://bsky.social/oauth/par';
        $this->expectExceptionMessage('is not served over https');

        $this->createClient()->startAuthorization(null, 'state-1');
    }

    // An endpoint that is no address is refused like one outside https
    public function testAnEndpointThatIsNoStringIsRefused(): void
    {
        $this->parEndpoint = ['https://bsky.social/oauth/par'];
        $this->expectExceptionMessage('is not served over https');

        $this->createClient()->startAuthorization(null, 'state-1');
    }

    // The session's own server is asked through the same guard, even when the container hands a raw client over
    public function testTheSessionServerOnThePrivateNetworkIsNeverAsked(): void
    {
        $this->config[BlueskyOAuthClient::SESSION_SLUG] = (string) json_encode([...$this->pending(), 'did' => 'did:plc:abc', 'pds' => 'https://127.0.0.1', 'accessToken' => 'fresh', 'refreshToken' => 'refresh-fresh', 'expiresAt' => time() + 1800]);
        $this->expectException(TransportException::class);

        try {
            $this->createClient(resolved: false)->call('com.atproto.repo.createRecord', ['json' => ['repo' => 'did:plc:abc']]);
        } finally {
            $this->assertSame([], $this->requests);
        }
    }

    // A refusal's explanation reaches the screen short and without markup
    public function testARefusalIsShownShort(): void
    {
        $this->parRefusal = '<b>' . str_repeat('x', 500) . '</b>';

        try {
            $this->createClient()->startAuthorization(null, 'state-1');
            $this->fail('The refusal was not reported.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Bluesky refused the authorization request: ' . str_repeat('x', 200), $exception->getMessage());
        }
    }
}
