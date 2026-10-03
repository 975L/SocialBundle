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
use c975L\SocialBundle\Service\BlueskyPublisher;
use c975L\SocialBundle\Service\SocialImageExporter;
use c975L\UiBundle\Model\SocialContent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class BlueskyPublisherTest extends TestCase
{
    /** @var list<array{url: string, body: string}> */
    private array $requests = [];

    // The OAuth connection stubbed: every call recorded with its json body, Bluesky's refusal thrown the way BlueskyOAuthClient words it
    private function createPublisher(bool $connected = true, bool $refused = false, ?string $mode = null, string $remoteImage = ''): BlueskyPublisher
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap([
            ['social-bluesky-publish-mode', $mode],
        ]);

        $oauthClient = $this->createStub(BlueskyOAuthClient::class);
        $oauthClient->method('isConnected')->willReturn($connected);
        $oauthClient->method('getDid')->willReturn('did:plc:abc');
        $oauthClient->method('call')->willReturnCallback(function (string $method, array $options) use ($refused): array {
            $this->requests[] = ['url' => $method, 'body' => (string) json_encode($options['json'] ?? [])];

            return match (true) {
                'com.atproto.repo.uploadBlob' === $method => ['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafy'], 'mimeType' => 'image/png', 'size' => 70]],
                $refused => throw new \RuntimeException('Bluesky refused com.atproto.repo.createRecord: Record too long'),
                default => ['uri' => 'at://did:plc:abc/app.bsky.feed.post/3k', 'cid' => 'bafy'],
            };
        });

        // Anything else is the image of a content read from a page, downloaded from where it is
        $httpClient = new MockHttpClient(function (string $method, string $url) use ($remoteImage): MockResponse {
            $this->requests[] = ['url' => $url, 'body' => ''];

            return new MockResponse($remoteImage);
        });

        return new BlueskyPublisher($configService, new SocialImageExporter($httpClient, $this->createStub(SiteUrlResolver::class), sys_get_temp_dir(), $configService), $oauthClient);
    }

    /**
     * @return array<string, mixed>
     */
    private function postedRecord(): array
    {
        return json_decode(end($this->requests)['body'], true)['record'];
    }

    // The OAuth connection is the only way in, no app password any more
    public function testIsConfiguredOnceConnected(): void
    {
        $this->assertTrue($this->createPublisher()->isConfigured());
        $this->assertFalse($this->createPublisher(connected: false)->isConfigured());
    }

    // Posted as the connected account
    public function testTheRecordIsWrittenInTheConnectedRepo(): void
    {
        $this->createPublisher()->publish('Hello', new SocialContent('1', 'Title', 'https://example.org'));

        $this->assertSame('com.atproto.repo.createRecord', $this->requests[0]['url']);
        $this->assertSame('did:plc:abc', json_decode($this->requests[0]['body'], true)['repo']);
    }

    public function testPublishReturnsThePostUri(): void
    {
        $uri = $this->createPublisher()->publish('Hello', new SocialContent('1', 'Title', 'https://example.org'));

        $this->assertSame('at://did:plc:abc/app.bsky.feed.post/3k', $uri);
    }

    // Facets count UTF-8 bytes: an accented title before the url shifts it by more bytes than characters, and a character-based offset would link the wrong slice
    public function testTheUrlFacetIsCountedInBytes(): void
    {
        $this->createPublisher()->publish("Été à Annecy\nhttps://example.org/photo.", new SocialContent('1', 'Title', 'https://example.org'));

        $facet = $this->postedRecord()['facets'][0];
        $this->assertSame(\strlen("Été à Annecy\n"), $facet['index']['byteStart']);
        $this->assertSame('https://example.org/photo', $facet['features'][0]['uri']);
        $this->assertSame($facet['index']['byteStart'] + \strlen('https://example.org/photo'), $facet['index']['byteEnd']);
    }

    public function testHashtagsBecomeTagFacetsWithoutTheirHash(): void
    {
        $this->createPublisher()->publish('Lac #photographie', new SocialContent('1', 'Title', 'https://example.org'));

        $facet = $this->postedRecord()['facets'][0];
        $this->assertSame('app.bsky.richtext.facet#tag', $facet['features'][0]['$type']);
        $this->assertSame('photographie', $facet['features'][0]['tag']);
    }

    public function testATextOverThreeHundredCharactersIsCut(): void
    {
        $this->createPublisher()->publish(str_repeat('é', 400), new SocialContent('1', 'Title', 'https://example.org'));

        $this->assertSame(300, mb_strlen($this->postedRecord()['text']));
    }

    public function testTheImageIsUploadedAndEmbeddedWithItsRatio(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bsky');
        imagepng(imagecreatetruecolor(4, 2), $path);

        try {
            $this->createPublisher()->publish('Hello', new SocialContent('1', 'Title', 'https://example.org', imagePath: $path));
        } finally {
            unlink($path);
        }

        $this->assertSame('com.atproto.repo.uploadBlob', $this->requests[0]['url']);
        $image = $this->postedRecord()['embed']['images'][0];
        $this->assertSame('Title', $image['alt']);
        $this->assertSame(['width' => 4, 'height' => 2], $image['aspectRatio']);
    }

    public function testAPostWithNoImageOnDiskGoesOutWithoutEmbed(): void
    {
        $this->createPublisher()->publish('Hello', new SocialContent('1', 'Title', 'https://example.org', imagePath: '/nowhere.jpg'));

        $this->assertCount(1, $this->requests);
        $this->assertArrayNotHasKey('embed', $this->postedRecord());
    }

    // What the run reports is Bluesky's own reason, not a bare status code
    public function testARefusalThrowsWithBlueskysMessage(): void
    {
        $this->expectExceptionMessage('Record too long');

        $this->createPublisher(refused: true)->publish('Hello', new SocialContent('1', 'Title', 'https://example.org'));
    }

    // A post nobody read going out under the site's name is the mistake not to make by default
    public function testReviewIsTheDefaultMode(): void
    {
        $this->assertFalse($this->createPublisher()->isAutomatic());
        $this->assertTrue($this->createPublisher(mode: 'auto')->isAutomatic());
    }

    public function testTheImageOfAContentReadFromAPageIsDownloaded(): void
    {
        $this->createPublisher(remoteImage: $this->png())->publish('Hello', new SocialContent('1', 'Title', 'https://example.org', imageUrl: 'https://example.org/og.png'));

        $this->assertSame('https://example.org/og.png', $this->requests[0]['url']);
        $this->assertSame(['width' => 4, 'height' => 2], $this->postedRecord()['embed']['images'][0]['aspectRatio']);
    }

    // The dry run shows the exact record, facets included, and calls nothing
    public function testPreviewBuildsTheRecordWithoutCallingBluesky(): void
    {
        $record = $this->createPublisher()->preview('Voir https://example.org', new SocialContent('1', 'Title', 'https://example.org'));

        $this->assertSame([], $this->requests);
        $this->assertSame('Voir https://example.org', $record['text']);
        $this->assertSame('https://example.org', $record['facets'][0]['features'][0]['uri']);
    }

    private function png(): string
    {
        ob_start();
        imagepng(imagecreatetruecolor(4, 2));

        return (string) ob_get_clean();
    }
}
