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
use c975L\SocialBundle\Service\GoogleOAuthClient;
use c975L\SocialBundle\Service\YoutubeClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class YoutubeClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: mixed}> */
    private array $calls = [];

    private string $video = '';

    protected function setUp(): void
    {
        $this->video = (string) tempnam(sys_get_temp_dir(), 'yt');
        file_put_contents($this->video, str_repeat('v', 1024));
    }

    protected function tearDown(): void
    {
        @unlink($this->video);
    }

    /**
     * @param list<MockResponse>    $responses
     * @param array<string, string> $config
     */
    private function client(array $responses = [], array $config = []): YoutubeClient
    {
        $config += [
            'social-google-oauth-client-id' => 'id',
            'social-google-oauth-client-secret' => 'secret',
            'social-youtube-oauth-refresh-token' => 'refresh',
        ];
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key): ?string => $config[$key] ?? null);

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? [], 'body' => $options['body'] ?? null];

            return array_shift($responses) ?? new MockResponse('{}');
        });

        return new YoutubeClient($httpClient, new GoogleOAuthClient($httpClient, $configService, new ArrayAdapter()));
    }

    // The Google OAuth client the listing uses is all it needs, the refresh token being what the connection fetches
    public function testItIsConfiguredByTheGoogleClientAndConnectedByItsOwnToken(): void
    {
        $this->assertTrue($this->client()->isConfigured());
        $this->assertTrue($this->client()->isConnected());
        $this->assertFalse($this->client(config: ['social-google-oauth-client-secret' => ''])->isConfigured());
        $this->assertFalse($this->client(config: ['social-youtube-oauth-refresh-token' => ''])->isConnected());
    }

    // Offline access and a forced consent, so a reconnection gets a refresh token again - with both scopes in one consent
    public function testTheAuthorizationAsksForOfflineAccessToBothScopes(): void
    {
        parse_str((string) parse_url($this->client()->getAuthorizationUrl('https://example.org/social/youtube/callback', 'state'), \PHP_URL_QUERY), $query);

        $this->assertSame([YoutubeClient::SCOPES, 'offline', 'consent', 'state'], [$query['scope'], $query['access_type'], $query['prompt'], $query['state']]);
    }

    // The code traded for the refresh token, then the channel asked for - what proves the right one consented
    public function testTheConnectionKeepsTheRefreshTokenAndTheChannel(): void
    {
        $connection = $this->client([
            new MockResponse('{"access_token": "access", "refresh_token": "new-refresh"}'),
            new MockResponse('{"items": [{"id": "UC123", "snippet": {"title": "Papa Câlin"}}]}'),
        ])->connect('code', 'https://example.org/social/youtube/callback');

        $this->assertSame(['refreshToken' => 'new-refresh', 'channelId' => 'UC123', 'channelTitle' => 'Papa Câlin'], $connection);
        $this->assertSame(YoutubeClient::API . '/channels?part=snippet&mine=true', $this->calls[1]['url']);
    }

    // An account holding no channel is said rather than stored
    public function testAnAccountWithoutAChannelIsRefused(): void
    {
        $this->expectExceptionMessageIsOrContains('holds no YouTube channel');

        $this->client([
            new MockResponse('{"access_token": "access", "refresh_token": "new-refresh"}'),
            new MockResponse('{"items": []}'),
        ])->connect('code', 'https://example.org/callback');
    }

    // The video announced with its metadata, then its bytes put where YouTube said, answering its id and the privacy it actually got
    public function testAVideoIsUploadedInOneResumableSession(): void
    {
        $uploaded = $this->client([
            new MockResponse('{"access_token": "access"}'),
            new MockResponse('', ['response_headers' => ['location' => 'https://upload.example/session']]),
            new MockResponse('{"id": "vid1", "status": {"privacyStatus": "private"}}'),
        ])->uploadVideo($this->video, 'video/mp4', ['snippet' => ['title' => 'Titre']]);

        $this->assertSame(['id' => 'vid1', 'privacyStatus' => 'private'], $uploaded);
        $this->assertStringStartsWith(YoutubeClient::UPLOAD . '?uploadType=resumable', $this->calls[1]['url']);
        $this->assertContains('x-upload-content-length: 1024', array_map(strtolower(...), $this->calls[1]['headers']));
        $this->assertSame(['PUT', 'https://upload.example/session'], [$this->calls[2]['method'], $this->calls[2]['url']]);
    }

    // The channel's token is minted from its own refresh token, never the listing's
    public function testTheAccessTokenComesFromTheChannelsRefreshToken(): void
    {
        $this->assertSame('access', $this->client([new MockResponse('{"access_token": "access"}')], ['social-google-oauth-refresh-token' => 'listing'])->getAccessToken());
        $this->assertStringContainsString('refresh_token=refresh', (string) $this->calls[0]['body']);
    }

    // YouTube's own reason, such as an exhausted quota, is what the refusal carries
    public function testARefusedUploadSaysWhy(): void
    {
        $this->expectExceptionMessageIsOrContains('quota');

        $this->client([
            new MockResponse('{"access_token": "access"}'),
            new MockResponse('{"error": {"message": "The request cannot be completed because you have exceeded your quota."}}', ['http_code' => 403]),
        ])->uploadVideo($this->video, 'video/mp4', []);
    }
}
