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
use c975L\SocialBundle\Model\PostMedia;
use c975L\SocialBundle\Service\BlueskyOAuthClient;
use c975L\SocialBundle\Service\BlueskyPublisher;
use c975L\SocialBundle\Service\SocialImageExporter;
use c975L\UiBundle\Model\SocialContent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class BlueskyPublisherTest extends TestCase
{
    /** @var list<array{url: string, body: string, type?: string, bytes?: string}> */
    private array $requests = [];

    // The OAuth connection stubbed: every call recorded with its json body, Bluesky's refusal thrown the way BlueskyOAuthClient words it
    private function createPublisher(bool $connected = true, bool $refused = false, string $remoteImage = ''): BlueskyPublisher
    {
        $configService = $this->createStub(ConfigServiceInterface::class);

        $oauthClient = $this->createStub(BlueskyOAuthClient::class);
        $oauthClient->method('isConnected')->willReturn($connected);
        $oauthClient->method('getDid')->willReturn('did:plc:abc');
        $oauthClient->method('call')->willReturnCallback(function (string $method, array $options) use ($refused): array {
            $this->requests[] = ['url' => $method, 'body' => (string) json_encode($options['json'] ?? []), 'type' => $options['headers']['Content-Type'] ?? '', 'bytes' => $options['body'] ?? ''];

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

        return new BlueskyPublisher(new SocialImageExporter($httpClient, $this->createStub(SiteUrlResolver::class), sys_get_temp_dir(), $configService), $oauthClient);
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
        $this->expectExceptionMessageIsOrContains('Record too long');

        $this->createPublisher(refused: true)->publish('Hello', new SocialContent('1', 'Title', 'https://example.org'));
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

    // Each picture uploaded with its own bytes, then embedded in its order with its alt and ratio
    public function testThePostsPicturesReplaceTheContentImage(): void
    {
        $first = $this->tempFile('first');
        $second = $this->tempFile('second');

        try {
            $this->createPublisher()->publish('Hello', new SocialContent('1', 'Title', 'https://example.org', imageUrl: 'https://example.org/og.png'), [
                new PostMedia($first, 'https://example.org/1.jpg', 'image/jpeg', width: 2048, height: 1536, alt: 'Lake'),
                new PostMedia($second, 'https://example.org/2.jpg', 'image/jpeg'),
            ]);
        } finally {
            unlink($first);
            unlink($second);
        }

        $uploads = array_values(array_filter($this->requests, static fn (array $request): bool => 'com.atproto.repo.uploadBlob' === $request['url']));
        $this->assertCount(2, $uploads);
        $this->assertSame(['first', 'second'], array_column($uploads, 'bytes'));
        $this->assertSame('image/jpeg', $uploads[0]['type']);
        $this->assertCount(3, $this->requests);

        $embed = $this->postedRecord()['embed'];
        $this->assertSame('app.bsky.embed.images', $embed['$type']);
        $this->assertCount(2, $embed['images']);
        $this->assertSame('Lake', $embed['images'][0]['alt']);
        $this->assertSame(['width' => 2048, 'height' => 1536], $embed['images'][0]['aspectRatio']);
        $this->assertSame('', $embed['images'][1]['alt']);
        $this->assertArrayNotHasKey('aspectRatio', $embed['images'][1]);
    }

    // A video goes as embed.video, the pictures given with it ignored
    public function testAVideoIsUploadedAndEmbeddedAsVideo(): void
    {
        $video = $this->tempFile('video');
        $picture = $this->tempFile('picture');

        try {
            $this->createPublisher()->publish('Hello', new SocialContent('1', 'Title', 'https://example.org'), [
                new PostMedia($picture, 'https://example.org/1.jpg', 'image/jpeg'),
                new PostMedia($video, 'https://example.org/v.mp4', 'video/mp4', width: 1080, height: 1920, duration: 12.5, alt: 'Waves'),
            ]);
        } finally {
            unlink($video);
            unlink($picture);
        }

        $this->assertCount(2, $this->requests);
        $this->assertSame('video', $this->requests[0]['bytes']);
        $this->assertSame('video/mp4', $this->requests[0]['type']);
        $embed = $this->postedRecord()['embed'];
        $this->assertSame('app.bsky.embed.video', $embed['$type']);
        $this->assertSame('bafy', $embed['video']['ref']['$link']);
        $this->assertSame('Waves', $embed['alt']);
        $this->assertSame(['width' => 1080, 'height' => 1920], $embed['aspectRatio']);
    }

    // The dry run shows each media by its url and uploads nothing
    public function testPreviewShowsTheMediasWithoutUploading(): void
    {
        $record = $this->createPublisher()->preview('Hello', new SocialContent('1', 'Title', 'https://example.org'), [
            new PostMedia('/nowhere.jpg', 'https://example.org/1.jpg', 'image/jpeg'),
        ]);

        $this->assertSame([], $this->requests);
        $this->assertSame('https://example.org/1.jpg', $record['embed']['images'][0]['image']);
    }

    public function testTheMediaRulesFollowTheLexicons(): void
    {
        $rules = $this->createPublisher()->getMediaRules();

        $this->assertSame(4, $rules->maxImages);
        $this->assertTrue($rules->video);
        $this->assertFalse($rules->mix);
        $this->assertFalse($rules->required);
        $this->assertSame(['image/jpeg', 'image/png', 'image/webp', 'image/gif'], $rules->imageTypes);
        $this->assertSame(2000000, $rules->maxImageBytes);
        $this->assertSame(['video/mp4'], $rules->videoTypes);
        $this->assertSame(300000000, $rules->maxVideoBytes);
        $this->assertSame(600.0, $rules->maxDuration);
    }

    // A media file holding these bytes
    private function tempFile(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'bsky');
        file_put_contents($path, $bytes);

        return $path;
    }

    private function png(): string
    {
        ob_start();
        imagepng(imagecreatetruecolor(4, 2));

        return (string) ob_get_clean();
    }
}
