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
use c975L\SocialBundle\Service\LinkedInClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class LinkedInClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: mixed}> */
    private array $calls = [];

    /**
     * @param list<MockResponse>    $responses
     * @param array<string, string> $config
     */
    private function client(array $responses = [], array $config = []): LinkedInClient
    {
        $config += [
            'social-linkedin-client-id' => 'id',
            'social-linkedin-client-secret' => 'secret',
            'social-linkedin-access-token' => 'token',
            'social-linkedin-member-id' => 'abc',
            'social-linkedin-token-expires-at' => new \DateTimeImmutable('+30 days')->format(\DateTimeInterface::ATOM),
        ];
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key): ?string => $config[$key] ?? null);

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? [], 'body' => $options['body'] ?? null];

            return array_shift($responses) ?? new MockResponse('{}');
        });

        return new LinkedInClient($httpClient, $configService, 0);
    }

    // The code traded for the token, then the member asked who they are - what the posts are signed with
    public function testTheConnectionKeepsTheTokenTheMemberAndTheEnd(): void
    {
        $connection = $this->client([
            new MockResponse('{"access_token": "new-token", "expires_in": 5184000}'),
            new MockResponse('{"sub": "xyz", "name": "Laurent Marquet"}'),
        ])->connect('code', 'https://example.org/social/linkedin/callback');

        $this->assertSame('new-token', $connection['accessToken']);
        $this->assertSame('xyz', $connection['memberId']);
        $this->assertSame('Laurent Marquet', $connection['name']);
        $this->assertEqualsWithDelta(new \DateTimeImmutable('+60 days')->getTimestamp(), $connection['expiresAt']->getTimestamp(), 5);
        $this->assertSame('https://api.linkedin.com/v2/userinfo', $this->calls[1]['url']);
    }

    // A refused code says why, rather than storing nothing silently
    public function testARefusedCodeSaysWhy(): void
    {
        $this->expectExceptionMessageIsOrContains('The code expired');

        $this->client([new MockResponse('{"error_description": "The code expired"}', ['http_code' => 400])])->connect('code', 'https://example.org/callback');
    }

    // The post's urn comes back in a header, and every call to the versioned API names its version
    public function testAPostAnswersTheUrnLinkedInPutsInAHeader(): void
    {
        $urn = $this->client([new MockResponse('', ['http_code' => 201, 'response_headers' => ['x-restli-id' => 'urn:li:share:1']])])->createPost(['commentary' => 'x']);

        $this->assertSame('urn:li:share:1', $urn);
        $this->assertContains('LinkedIn-Version: ' . LinkedInClient::VERSION, $this->calls[0]['headers']);
    }

    // An expired token is no connection: the posts would only fail one after the other
    public function testAnExpiredTokenIsNoConnection(): void
    {
        $this->assertTrue($this->client()->isConnected());
        $this->assertFalse($this->client(config: ['social-linkedin-token-expires-at' => '2020-01-01T00:00:00+00:00'])->isConnected());
        $this->assertFalse($this->client(config: ['social-linkedin-access-token' => ''])->isConnected());
    }

    // The image is announced, then its bytes put where LinkedIn said, the urn being what the post refers to
    public function testAnImageIsUploadedWhereLinkedInSays(): void
    {
        $urn = $this->client([
            new MockResponse('{"value": {"uploadUrl": "https://upload.example.org/1", "image": "urn:li:image:1"}}'),
            new MockResponse('', ['http_code' => 201]),
        ])->uploadImage('bytes');

        $this->assertSame('urn:li:image:1', $urn);
        $this->assertSame(['PUT', 'https://upload.example.org/1'], [$this->calls[1]['method'], $this->calls[1]['url']]);
    }

    // The video announced with its size, each part put at its own address, then finalized with the ETags in their order
    public function testAVideoIsUploadedInPartsThenFinalized(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'linkedin');
        file_put_contents($path, '0123456789');

        try {
            $urn = $this->client([
                new MockResponse((string) json_encode(['value' => [
                    'video' => 'urn:li:video:1',
                    'uploadToken' => '',
                    'uploadInstructions' => [
                        ['uploadUrl' => 'https://upload.example.org/1', 'firstByte' => 0, 'lastByte' => 5],
                        ['uploadUrl' => 'https://upload.example.org/2', 'firstByte' => 6, 'lastByte' => 9],
                    ],
                ]])),
                new MockResponse('', ['http_code' => 200, 'response_headers' => ['etag' => 'tag-1']]),
                new MockResponse('', ['http_code' => 200, 'response_headers' => ['etag' => 'tag-2']]),
                new MockResponse('', ['http_code' => 200]),
                new MockResponse('{"status": "PROCESSING"}'),
                new MockResponse('{"status": "AVAILABLE"}'),
            ])->uploadVideo($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame('urn:li:video:1', $urn);
        $this->assertSame('https://api.linkedin.com/rest/videos?action=initializeUpload', $this->calls[0]['url']);
        $this->assertSame(10, json_decode($this->calls[0]['body'], true)['initializeUploadRequest']['fileSizeBytes']);
        $this->assertSame([['PUT', 'https://upload.example.org/1', '012345'], ['PUT', 'https://upload.example.org/2', '6789']], [
            [$this->calls[1]['method'], $this->calls[1]['url'], $this->calls[1]['body']],
            [$this->calls[2]['method'], $this->calls[2]['url'], $this->calls[2]['body']],
        ]);
        $this->assertSame('https://api.linkedin.com/rest/videos?action=finalizeUpload', $this->calls[3]['url']);
        $this->assertSame(['video' => 'urn:li:video:1', 'uploadToken' => '', 'uploadedPartIds' => ['tag-1', 'tag-2']], json_decode($this->calls[3]['body'], true)['finalizeUploadRequest']);
        $this->assertContains('LinkedIn-Version: ' . LinkedInClient::VERSION, $this->calls[3]['headers']);
        $this->assertSame(['GET', 'https://api.linkedin.com/rest/videos/urn%3Ali%3Avideo%3A1'], [$this->calls[5]['method'], $this->calls[5]['url']]);
    }

    // A video LinkedIn could not process is not put in a post
    public function testAVideoLinkedInCouldNotProcessStopsThePost(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'linkedin');
        file_put_contents($path, '0123');
        $this->expectExceptionMessageIsOrContains('could not process');

        try {
            $this->client([
                new MockResponse('{"value": {"video": "urn:li:video:1", "uploadInstructions": [{"uploadUrl": "https://upload.example.org/1", "firstByte": 0, "lastByte": 3}]}}'),
                new MockResponse('', ['http_code' => 200, 'response_headers' => ['etag' => 'tag-1']]),
                new MockResponse('', ['http_code' => 200]),
                new MockResponse('{"status": "PROCESSING_FAILED"}'),
            ])->uploadVideo($path);
        } finally {
            @unlink($path);
        }
    }

    // A part LinkedIn does not acknowledge stops the upload rather than finalizing a broken video
    public function testAPartWithNoEtagStopsTheUpload(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'linkedin');
        file_put_contents($path, '0123');
        $this->expectExceptionMessageIsOrContains('did not acknowledge');

        try {
            $this->client([
                new MockResponse('{"value": {"video": "urn:li:video:1", "uploadInstructions": [{"uploadUrl": "https://upload.example.org/1", "firstByte": 0, "lastByte": 3}]}}'),
                new MockResponse('', ['http_code' => 200]),
            ])->uploadVideo($path);
        } finally {
            @unlink($path);
        }
    }
}
