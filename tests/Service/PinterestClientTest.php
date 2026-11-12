<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Service\PinterestClient;
use c975L\SocialBundle\Service\ProductionGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

class PinterestClientTest extends TestCase
{
    use ClientTestTrait;

    /**
     * @param list<MockResponse>    $responses
     * @param array<string, string> $config
     */
    private function client(array $responses = [], array $config = [], string $environment = 'prod'): PinterestClient
    {
        $config += [
            'social-pinterest-client-id' => 'id',
            'social-pinterest-client-secret' => 'secret',
            'social-pinterest-access-token' => 'pina-token',
            'social-pinterest-refresh-token' => 'pinr-token',
            'social-pinterest-token-expires-at' => new \DateTimeImmutable('+20 days')->format(\DateTimeInterface::ATOM),
            'social-pinterest-refresh-token-expires-at' => new \DateTimeImmutable('+50 days')->format(\DateTimeInterface::ATOM),
            'social-pinterest-board-id' => '42',
        ];

        return new PinterestClient($this->httpClient($responses), $this->configService($config), $this->configValueWriter(), 0, new ProductionGuard($environment));
    }

    // The consent asks what pinning needs, back to the route the app declares
    public function testTheConsentAsksTheScopesOfPinning(): void
    {
        parse_str((string) parse_url($this->client()->getAuthorizationUrl('https://example.org/social/pinterest/callback', 'state'), \PHP_URL_QUERY), $query);

        $this->assertSame(['code', 'id', 'https://example.org/social/pinterest/callback', 'state', PinterestClient::SCOPES], [$query['response_type'], $query['client_id'], $query['redirect_uri'], $query['state'], $query['scope']]);
    }

    // The code traded for both tokens with the app's own credentials as Basic auth, then the account asked its name
    public function testTheConnectionKeepsBothTokensTheirEndsAndTheAccount(): void
    {
        $values = $this->client([
            new MockResponse('{"access_token": "pina-new", "refresh_token": "pinr-new", "expires_in": 2592000, "refresh_token_expires_in": 5184000}'),
            new MockResponse('{"username": "papacalin"}'),
        ])->connect('code', 'https://example.org/social/pinterest/callback');

        $this->assertSame(['pina-new', 'pinr-new', 'papacalin'], [$values['social-pinterest-access-token'], $values['social-pinterest-refresh-token'], $values['social-pinterest-username']]);
        $this->assertEqualsWithDelta(new \DateTimeImmutable('+30 days')->getTimestamp(), new \DateTimeImmutable($values['social-pinterest-token-expires-at'])->getTimestamp(), 5);
        $this->assertEqualsWithDelta(new \DateTimeImmutable('+60 days')->getTimestamp(), new \DateTimeImmutable($values['social-pinterest-refresh-token-expires-at'])->getTimestamp(), 5);
        $this->assertSame('https://api.pinterest.com/v5/oauth/token', $this->calls[0]['url']);
        $this->assertContains('Authorization: Basic ' . base64_encode('id:secret'), $this->calls[0]['headers']);
        $this->assertStringContainsString('grant_type=authorization_code', (string) $this->calls[0]['body']);
        $this->assertSame('https://api.pinterest.com/v5/user_account', $this->calls[1]['url']);
        $this->assertContains('Authorization: Bearer pina-new', $this->calls[1]['headers']);
    }

    // A refusal says Pinterest's own reason, nothing written
    public function testARefusedConnectionSaysPinterestsReason(): void
    {
        $this->expectExceptionMessage('Pinterest refused the connection: Invalid code');

        $this->client([new MockResponse('{"message": "Invalid code"}', ['http_code' => 400])])->connect('code', 'https://example.org/social/pinterest/callback');
    }

    // A refresh spends the refresh token for new ones, stored at once - the refresh token's end given as a timestamp on this grant
    public function testARefreshStoresTheNewTokens(): void
    {
        $end = new \DateTimeImmutable('+60 days')->getTimestamp();
        $this->client([new MockResponse('{"access_token": "pina-new", "refresh_token": "pinr-new", "expires_in": 2592000, "refresh_token_expires_at": ' . $end . '}')])->refresh();

        $this->assertStringContainsString('grant_type=refresh_token', (string) $this->calls[0]['body']);
        $this->assertStringContainsString('refresh_token=pinr-token', (string) $this->calls[0]['body']);
        $this->assertStringContainsString('continuous_refresh=true', (string) $this->calls[0]['body']);
        $this->assertSame(['pina-new', 'pinr-new'], [$this->written[0]['social-pinterest-access-token'], $this->written[0]['social-pinterest-refresh-token']]);
        $this->assertSame($end, new \DateTimeImmutable((string) $this->written[0]['social-pinterest-refresh-token-expires-at'])->getTimestamp());
    }

    // Outside production the single-use refresh token is never spent: production's connection would end with it
    public function testNoRefreshOutsideProduction(): void
    {
        $this->expectExceptionMessage('The Pinterest tokens are only renewed in production.');

        try {
            $this->client(environment: 'dev')->refresh();
        } finally {
            $this->assertSame([], $this->calls);
        }
    }

    // A refresh that hands no new refresh token keeps the one in place, the access token renewed all the same
    public function testARefreshWithoutANewRefreshTokenKeepsTheOldOne(): void
    {
        $this->client([new MockResponse('{"access_token": "pina-new", "expires_in": 2592000}')])->refresh();

        $this->assertSame('pina-new', $this->written[0]['social-pinterest-access-token']);
        $this->assertArrayNotHasKey('social-pinterest-refresh-token', $this->written[0]);
        $this->assertArrayNotHasKey('social-pinterest-refresh-token-expires-at', $this->written[0]);
    }

    // An HTML error page ends as Pinterest's refusal, never as a JsonException the nightly command would not catch
    public function testAnUnreadableAnswerIsARefusal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Pinterest refused the connection: 502');

        $this->client([new MockResponse('<html>Bad Gateway</html>', ['http_code' => 502, 'response_headers' => ['content-type' => 'text/html']])])->refresh();
    }

    // Ends Pinterest would not give are its documented ones, never "now"
    public function testMissingLifetimesFallBackToTheDocumentedOnes(): void
    {
        $values = $this->client([new MockResponse('{"access_token": "pina-new", "refresh_token": "pinr-new"}'), new MockResponse('{"username": "papacalin"}')])->connect('code', 'https://example.org/social/pinterest/callback');

        $this->assertEqualsWithDelta(new \DateTimeImmutable('+30 days')->getTimestamp(), new \DateTimeImmutable($values['social-pinterest-token-expires-at'])->getTimestamp(), 5);
        $this->assertEqualsWithDelta(new \DateTimeImmutable('+60 days')->getTimestamp(), new \DateTimeImmutable($values['social-pinterest-refresh-token-expires-at'])->getTimestamp(), 5);
    }

    // An account Pinterest does not name is not connected
    public function testAConnectionWithoutAnAccountNameIsRefused(): void
    {
        $this->expectExceptionMessage('Pinterest did not answer the account name.');

        $this->client([new MockResponse('{"access_token": "pina-new", "refresh_token": "pinr-new"}'), new MockResponse('{}')])->connect('code', 'https://example.org/social/pinterest/callback');
    }

    // A call refreshes an access token already over, one only near its end being left to the nightly command
    public function testACallRefreshesATokenOnlyOnceOver(): void
    {
        $this->client([
            new MockResponse('{"access_token": "pina-new", "refresh_token": "pinr-new", "expires_in": 2592000, "refresh_token_expires_in": 5184000}'),
            new MockResponse('{"items": []}'),
        ], ['social-pinterest-token-expires-at' => new \DateTimeImmutable('-1 hour')->format(\DateTimeInterface::ATOM)])->boards();

        $this->assertSame('https://api.pinterest.com/v5/oauth/token', $this->calls[0]['url']);
        $this->assertCount(1, $this->written);

        $this->calls = [];
        $this->client([new MockResponse('{"items": []}')], ['social-pinterest-token-expires-at' => new \DateTimeImmutable('+2 days')->format(\DateTimeInterface::ATOM)])->boards();
        $this->assertCount(1, $this->calls);
    }

    // The public boards page after page, each with its name
    public function testTheBoardsAreReadPageAfterPage(): void
    {
        $boards = $this->client([
            new MockResponse('{"items": [{"id": "1", "name": "Histoires"}], "bookmark": "next"}'),
            new MockResponse('{"items": [{"id": "2", "name": "Cartes"}], "bookmark": null}'),
        ])->boards();

        $this->assertSame([['id' => '1', 'name' => 'Histoires'], ['id' => '2', 'name' => 'Cartes']], $boards);
        $this->assertSame('https://api.pinterest.com/v5/boards?page_size=100&privacy=PUBLIC', $this->calls[0]['url']);
        $this->assertSame('https://api.pinterest.com/v5/boards?page_size=100&privacy=PUBLIC&bookmark=next', $this->calls[1]['url']);
    }

    // A pin goes on the board picked, its id answered - a refusal saying Pinterest's reason
    public function testAPinGoesOnTheBoardPicked(): void
    {
        $this->assertSame('987', $this->client([new MockResponse('{"id": "987"}', ['http_code' => 201])])->createPin(['title' => 'Titre']));
        $this->assertSame('https://api.pinterest.com/v5/pins', $this->calls[0]['url']);
        $this->assertSame(['board_id' => '42', 'title' => 'Titre'], json_decode((string) $this->calls[0]['body'], true));

        $this->expectExceptionMessage('Pinterest refused /v5/pins: Board not found');
        $this->client([new MockResponse('{"message": "Board not found"}', ['http_code' => 404])])->createPin(['title' => 'Titre']);
    }

    // In the sandbox every call goes to its own API, the tokens traded there too
    public function testTheSandboxTakesEveryCallTokensIncluded(): void
    {
        $client = $this->client([
            new MockResponse('{"id": "987"}', ['http_code' => 201]),
            new MockResponse('{"access_token": "pina-new"}'),
        ], ['social-pinterest-sandbox' => 'true']);

        $this->assertTrue($client->isSandbox());
        $client->createPin(['title' => 'Titre']);
        $client->refresh();

        $this->assertSame(['https://api-sandbox.pinterest.com/v5/pins', 'https://api-sandbox.pinterest.com/v5/oauth/token'], array_column($this->calls, 'url'));
        $this->assertFalse($this->client()->isSandbox());
    }

    // The sandbox starts empty: a board is created there, never on the real account
    public function testTheSandboxCreatesItsBoardWhenItHasNone(): void
    {
        $boards = $this->client([
            new MockResponse('{"items": []}'),
            new MockResponse('{"id": "7", "name": "Sandbox"}', ['http_code' => 201]),
        ], ['social-pinterest-sandbox' => 'true'])->boards();

        $this->assertSame([['id' => '7', 'name' => 'Sandbox']], $boards);
        $this->assertSame(['method' => 'POST', 'url' => 'https://api-sandbox.pinterest.com/v5/boards'], ['method' => $this->calls[1]['method'], 'url' => $this->calls[1]['url']]);
        $this->assertSame(['name' => PinterestClient::SANDBOX_BOARD, 'privacy' => 'PUBLIC'], json_decode((string) $this->calls[1]['body'], true));

        $this->calls = [];
        $this->assertSame([], $this->client([new MockResponse('{"items": []}')])->boards());
        $this->assertCount(1, $this->calls);
    }

    // Connected while the refresh token lives, whatever the access token says - it renews itself
    public function testConnectedWhileTheRefreshTokenLives(): void
    {
        $this->assertTrue($this->client(config: ['social-pinterest-token-expires-at' => new \DateTimeImmutable('-1 day')->format(\DateTimeInterface::ATOM)])->isConnected());
        $this->assertFalse($this->client(config: ['social-pinterest-refresh-token-expires-at' => new \DateTimeImmutable('-1 day')->format(\DateTimeInterface::ATOM)])->isConnected());
        $this->assertFalse($this->client(config: ['social-pinterest-refresh-token' => ''])->isConnected());
        $this->assertFalse($this->client(config: ['social-pinterest-client-secret' => ''])->isConfigured());
    }

    // A video is registered, sent to the storage Pinterest names with its parameters and no token, then asked about until processed
    public function testAVideoIsUploadedThenWaitedFor(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pin');
        file_put_contents($path, 'video');
        $client = $this->client([
            new MockResponse('{"media_id": "555", "upload_url": "https://storage.example.org/", "upload_parameters": {"key": "item-1"}}', ['http_code' => 201]),
            new MockResponse('', ['http_code' => 204]),
            new MockResponse('{"status": "processing"}'),
            new MockResponse('{"status": "succeeded"}'),
        ]);

        $this->assertSame('555', $client->uploadVideo($path));
        $this->assertSame(['media_type' => 'video'], json_decode((string) $this->calls[0]['body'], true));
        $this->assertSame('https://storage.example.org/', $this->calls[1]['url']);
        $this->assertStringNotContainsString('pina-token', implode("\n", (array) $this->calls[1]['headers']));
        $this->assertSame('https://api.pinterest.com/v5/media/555', $this->calls[3]['url']);
        unlink($path);
    }

    // A video Pinterest could not process is a refusal
    public function testAVideoPinterestCouldNotProcessIsRefused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pin');
        $client = $this->client([
            new MockResponse('{"media_id": "555", "upload_url": "https://storage.example.org/"}', ['http_code' => 201]),
            new MockResponse('', ['http_code' => 204]),
            new MockResponse('{"status": "failed"}'),
        ]);

        try {
            $this->expectExceptionMessage('Pinterest could not process the video.');
            $client->uploadVideo($path);
        } finally {
            unlink($path);
        }
    }

    // A video still processing after its 30 asks stops the pin, to be published again later
    public function testAVideoStillProcessingStopsAfterItsAttempts(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pin');
        $client = $this->client([
            new MockResponse('{"media_id": "555", "upload_url": "https://storage.example.org/"}', ['http_code' => 201]),
            new MockResponse('', ['http_code' => 204]),
            ...array_fill(0, 40, new MockResponse('{"status": "processing"}')),
        ]);

        try {
            $this->expectExceptionMessage('Pinterest is still processing the video');
            $client->uploadVideo($path);
        } finally {
            unlink($path);
        }
    }
}
