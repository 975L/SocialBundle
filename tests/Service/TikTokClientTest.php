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
use c975L\SocialBundle\Service\TikTokClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

class TikTokClientTest extends TestCase
{
    use ClientTestTrait;

    /**
     * @param list<MockResponse>    $responses
     * @param array<string, string> $config
     */
    private function client(array $responses = [], array $config = []): TikTokClient
    {
        $config += [
            'social-tiktok-client-key' => 'key',
            'social-tiktok-client-secret' => 'secret',
            'social-tiktok-access-token' => 'act.token',
            'social-tiktok-refresh-token' => 'rft.token',
            'social-tiktok-token-expires-at' => new \DateTimeImmutable('+1 hour')->format(\DateTimeInterface::ATOM),
            'social-tiktok-refresh-token-expires-at' => new \DateTimeImmutable('+300 days')->format(\DateTimeInterface::ATOM),
        ];

        return new TikTokClient($this->httpClient($responses, '{"data": {}, "error": {"code": "ok"}}'), $this->configService($config), $this->configValueWriter(), new LockFactory(new InMemoryStore()));
    }

    // The consent asks reading the account and posting on it, nothing else, back to the route the app declares
    public function testTheConsentAsksTheScopesOfPosting(): void
    {
        parse_str((string) parse_url($this->client()->getAuthorizationUrl('https://example.org/social/tiktok/callback', 'state'), \PHP_URL_QUERY), $query);

        $this->assertSame(['key', 'code', 'user.info.basic,video.publish', 'https://example.org/social/tiktok/callback', 'state'], [$query['client_key'], $query['response_type'], $query['scope'], $query['redirect_uri'], $query['state']]);
    }

    // The code traded for both tokens with the app's key and secret in the form, then the account asked its name
    public function testTheConnectionKeepsBothTokensTheirEndsAndTheAccount(): void
    {
        $values = $this->client([
            new MockResponse('{"access_token": "act.new", "refresh_token": "rft.new", "expires_in": 7200, "refresh_expires_in": 15552000, "open_id": "open-1"}'),
            new MockResponse('{"data": {"user": {"open_id": "open-1", "display_name": "Mystic Fable Studios"}}, "error": {"code": "ok"}}'),
        ])->connect('code', 'https://example.org/social/tiktok/callback');

        $this->assertSame(['act.new', 'rft.new', 'open-1', 'Mystic Fable Studios'], [$values['social-tiktok-access-token'], $values['social-tiktok-refresh-token'], $values['social-tiktok-open-id'], $values['social-tiktok-display-name']]);
        $this->assertEqualsWithDelta(new \DateTimeImmutable('+2 hours')->getTimestamp(), new \DateTimeImmutable($values['social-tiktok-token-expires-at'])->getTimestamp(), 5);
        $this->assertEqualsWithDelta(new \DateTimeImmutable('+180 days')->getTimestamp(), new \DateTimeImmutable($values['social-tiktok-refresh-token-expires-at'])->getTimestamp(), 5);
        $this->assertSame('https://open.tiktokapis.com/v2/oauth/token/', $this->calls[0]['url']);
        parse_str((string) $this->calls[0]['body'], $body);
        $this->assertSame(['key', 'secret', 'authorization_code', 'code'], [$body['client_key'], $body['client_secret'], $body['grant_type'], $body['code']]);
        $this->assertContains('Authorization: Bearer act.new', $this->calls[1]['headers']);
    }

    // A refusal of the code says TikTok's words, nothing being written
    public function testARefusedCodeSaysWhy(): void
    {
        $this->expectExceptionMessage('TikTok refused the connection: Authorization code is expired.');

        $this->client([new MockResponse('{"error": "invalid_grant", "error_description": "Authorization code is expired."}', ['http_code' => 400])])->connect('code', 'https://example.org/social/tiktok/callback');
    }

    // The refresh token traded for new tokens, stored at once
    public function testTheRefreshStoresTheNewTokens(): void
    {
        $this->client([new MockResponse('{"access_token": "act.next", "refresh_token": "rft.next", "expires_in": 7200, "refresh_expires_in": 15552000}')])->refresh();

        parse_str((string) $this->calls[0]['body'], $body);
        $this->assertSame(['refresh_token', 'rft.token'], [$body['grant_type'], $body['refresh_token']]);
        $this->assertSame(['act.next', 'rft.next'], [$this->written[0]['social-tiktok-access-token'], $this->written[0]['social-tiktok-refresh-token']]);
    }

    // No refresh token left behind the lock: refused, nothing asked of TikTok
    public function testARefreshWithoutAccountIsRefused(): void
    {
        try {
            $this->client([], ['social-tiktok-refresh-token' => ''])->refresh();
            $this->fail('A refresh without a refresh token has to be refused.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('No TikTok account is connected.', $exception->getMessage());
        }
        $this->assertSame([], $this->calls);
    }

    // An access token over is refreshed before the call, the call then made with the new one
    public function testACallRefreshesAnAccessTokenOver(): void
    {
        $this->client([
            new MockResponse('{"access_token": "act.next", "refresh_token": "rft.next", "expires_in": 7200}'),
            new MockResponse('{"data": {"publish_id": "p1"}, "error": {"code": "ok"}}'),
        ], ['social-tiktok-token-expires-at' => new \DateTimeImmutable('-1 hour')->format(\DateTimeInterface::ATOM)])->postVideo(['privacy_level' => 'SELF_ONLY'], 'https://example.org/video.mp4');

        $this->assertSame(['https://open.tiktokapis.com/v2/oauth/token/', 'https://open.tiktokapis.com/v2/post/publish/video/init/'], array_column($this->calls, 'url'));
    }

    // An answer not saying the lifetimes counts what TikTok documents
    public function testAnAnswerWithoutLifetimesCountsTheDocumentedOnes(): void
    {
        $this->client([new MockResponse('{"access_token": "act.next", "refresh_token": "rft.next"}')])->refresh();

        $this->assertEqualsWithDelta(new \DateTimeImmutable('+2 hours')->getTimestamp(), new \DateTimeImmutable((string) $this->written[0]['social-tiktok-token-expires-at'])->getTimestamp(), 5);
        $this->assertEqualsWithDelta(new \DateTimeImmutable('+180 days')->getTimestamp(), new \DateTimeImmutable((string) $this->written[0]['social-tiktok-refresh-token-expires-at'])->getTimestamp(), 5);
    }

    // An access token a few seconds from its end is refreshed too, rather than dying on the way to TikTok
    public function testACallRefreshesAnAccessTokenAboutToEnd(): void
    {
        $this->client([
            new MockResponse('{"access_token": "act.next", "refresh_token": "rft.next", "expires_in": 7200}'),
            new MockResponse('{"data": {"publish_id": "p1"}, "error": {"code": "ok"}}'),
        ], ['social-tiktok-token-expires-at' => new \DateTimeImmutable('+30 seconds')->format(\DateTimeInterface::ATOM)])->postVideo(['privacy_level' => 'SELF_ONLY'], 'https://example.org/video.mp4');

        $this->assertSame('https://open.tiktokapis.com/v2/oauth/token/', $this->calls[0]['url']);
    }

    // The configuration answering other values once its cache is dropped, as when another process stored new tokens during the wait for the lock
    /** @param array<string, string> $after */
    private function clientWhoseConfigChanges(array $after, MockResponse ...$responses): TikTokClient
    {
        $config = [
            'social-tiktok-access-token' => 'act.token',
            'social-tiktok-refresh-token' => 'rft.spent',
            'social-tiktok-token-expires-at' => new \DateTimeImmutable('-1 hour')->format(\DateTimeInterface::ATOM),
        ];
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static function (string $key) use (&$config): ?string {
            return $config[$key] ?? null;
        });
        $configService->method('invalidateCache')->willReturnCallback(static function () use (&$config, $after): void {
            $config = $after + $config;
        });

        return new TikTokClient($this->httpClient(array_values($responses), '{"data": {}, "error": {"code": "ok"}}'), $configService, $this->configValueWriter(), new LockFactory(new InMemoryStore()));
    }

    // The refresh token read behind the lock, the one read before it possibly spent by another process
    public function testTheRefreshSpendsTheTokenStoredBehindTheLock(): void
    {
        $this->clientWhoseConfigChanges(['social-tiktok-refresh-token' => 'rft.latest'], new MockResponse('{"access_token": "act.next", "refresh_token": "rft.next", "expires_in": 7200}'))->refresh();

        parse_str((string) $this->calls[0]['body'], $body);
        $this->assertSame('rft.latest', $body['refresh_token']);
    }

    // A call that waited for the lock uses what the other process renewed, spending nothing
    public function testACallLeavesAloneATokenRenewedDuringItsWait(): void
    {
        $this->clientWhoseConfigChanges([
            'social-tiktok-access-token' => 'act.renewed',
            'social-tiktok-token-expires-at' => new \DateTimeImmutable('+2 hours')->format(\DateTimeInterface::ATOM),
        ], new MockResponse('{"data": {"publish_id": "p1"}, "error": {"code": "ok"}}'))->postVideo(['privacy_level' => 'SELF_ONLY'], 'https://example.org/video.mp4');

        $this->assertSame(['https://open.tiktokapis.com/v2/post/publish/video/init/'], array_column($this->calls, 'url'));
        $this->assertContains('Authorization: Bearer act.renewed', $this->calls[0]['headers']);
        $this->assertSame([], $this->written);
    }

    // The lock given back when TikTok refuses too, the next refresh not waiting on it
    public function testTheLockIsReleasedOnARefusal(): void
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects($this->once())->method('acquire')->with(true)->willReturn(true);
        $lock->expects($this->once())->method('release');
        $lockFactory = $this->createStub(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);
        $config = ['social-tiktok-refresh-token' => 'rft.token'];

        $this->expectExceptionMessage('TikTok refused the connection: invalid_grant');

        new TikTokClient($this->httpClient([new MockResponse('{"error": "invalid_grant"}', ['http_code' => 400])]), $this->configService($config), $this->configValueWriter(), $lockFactory)->refresh();
    }

    // Outside production nothing is spent, a database imported locally holding production's refresh token
    public function testTheTokensAreOnlyRenewedInProduction(): void
    {
        $client = new TikTokClient($this->httpClient([]), $this->configService(['social-tiktok-refresh-token' => 'rft.token']), $this->configValueWriter(), new LockFactory(new InMemoryStore()), 'dev');

        $this->assertFalse($client->renewsHere());
        try {
            $client->refresh();
            $this->fail('A refresh outside production has to be refused.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('The TikTok tokens are only renewed in production.', $exception->getMessage());
        }
        $this->assertSame([], $this->calls);
        $this->assertSame([], $this->written);
    }

    // What the account allows, read once in a request, the post's screen and its check asking it together
    public function testTheAccountsSettingsAreReadOnce(): void
    {
        $client = $this->client([new MockResponse('{"data": {"creator_nickname": "Mystic", "privacy_level_options": ["FOLLOWER_OF_CREATOR", "SELF_ONLY"], "comment_disabled": false, "duet_disabled": true, "stitch_disabled": true, "max_video_post_duration_sec": 180}, "error": {"code": "ok"}}')]);

        $info = $client->creatorInfo();
        $client->creatorInfo();

        $this->assertSame(['nickname' => 'Mystic', 'privacy_options' => ['FOLLOWER_OF_CREATOR', 'SELF_ONLY'], 'comment_disabled' => false, 'duet_disabled' => true, 'stitch_disabled' => true, 'max_duration' => 180], $info);
        $this->assertCount(1, $this->calls);
        $this->assertSame('{}', $this->calls[0]['body']);
    }

    // Asked again just before a post, whatever the request already read
    public function testTheAccountsSettingsAreReadAgainBeforeAPost(): void
    {
        $answer = '{"data": {"creator_nickname": "Mystic", "privacy_level_options": ["SELF_ONLY"]}, "error": {"code": "ok"}}';
        $client = $this->client([new MockResponse($answer), new MockResponse($answer)]);

        $client->creatorInfo();
        $client->creatorInfo(true);

        $this->assertCount(2, $this->calls);
    }

    // Forgotten between a worker's messages, the next one asking the account again
    public function testTheAccountsSettingsAreForgottenOnReset(): void
    {
        $answer = '{"data": {"creator_nickname": "Mystic", "privacy_level_options": ["SELF_ONLY"]}, "error": {"code": "ok"}}';
        $client = $this->client([new MockResponse($answer), new MockResponse($answer)]);

        $client->creatorInfo();
        $client->reset();
        $client->creatorInfo();

        $this->assertCount(2, $this->calls);
    }

    // A refusal TikTok answers with a 200 is a refusal all the same, its code first, what says what to do
    public function testARefusalAnsweredWithA200Throws(): void
    {
        $this->expectExceptionMessage('TikTok refused /v2/post/publish/creator_info/query/: spam_risk_too_many_posts - The user has posted too much.');

        $this->client([new MockResponse('{"data": {}, "error": {"code": "spam_risk_too_many_posts", "message": "The user has posted too much."}}')])->creatorInfo();
    }

    // The video fetched by TikTok from its address, the post's id answered
    public function testTheVideoIsPulledFromItsAddress(): void
    {
        $publishId = $this->client([new MockResponse('{"data": {"publish_id": "v_pub_1"}, "error": {"code": "ok"}}')])->postVideo(['privacy_level' => 'SELF_ONLY'], 'https://example.org/video.mp4');

        $this->assertSame('v_pub_1', $publishId);
        $this->assertSame(['post_info' => ['privacy_level' => 'SELF_ONLY'], 'source_info' => ['source' => 'PULL_FROM_URL', 'video_url' => 'https://example.org/video.mp4']], json_decode((string) $this->calls[0]['body'], true));
    }

    // The processing's status, with TikTok's reason when it failed
    public function testTheStatusSaysTheReasonOfAFailure(): void
    {
        $status = $this->client([new MockResponse('{"data": {"status": "FAILED", "fail_reason": "duration_check_failed"}, "error": {"code": "ok"}}')])->status('v_pub_1');

        $this->assertSame(['status' => 'FAILED', 'reason' => 'duration_check_failed'], $status);
    }

    // Connected while the refresh token lasts, configured with both keys
    public function testConnectedWhileTheRefreshTokenLasts(): void
    {
        $this->assertTrue($this->client()->isConnected());
        $this->assertFalse($this->client(config: ['social-tiktok-refresh-token-expires-at' => new \DateTimeImmutable('-1 day')->format(\DateTimeInterface::ATOM)])->isConnected());
        $this->assertFalse($this->client(config: ['social-tiktok-client-secret' => ''])->isConfigured());
    }
}
