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
use c975L\SocialBundle\Model\PostMedia;
use c975L\SocialBundle\Service\FacebookPublisher;
use c975L\SocialBundle\Service\InstagramPublisher;
use c975L\SocialBundle\Service\MetaGraphClient;
use c975L\SocialBundle\Service\SocialImageExporter;
use c975L\UiBundle\Model\SocialContent;
use PHPUnit\Framework\TestCase;

class MetaPublishersTest extends TestCase
{
    /** @var list<array{method: string, path: string, parameters: array<string, string>}> */
    private array $calls = [];

    /**
     * @param list<array<string, mixed>> $answers
     */
    private function graph(array $answers): MetaGraphClient
    {
        $graph = $this->createStub(MetaGraphClient::class);
        $graph->method('pageToken')->willReturn('page-token');
        $graph->method('request')->willReturnCallback(function (string $method, string $path, array $parameters) use (&$answers): array {
            $this->calls[] = ['method' => $method, 'path' => $path, 'parameters' => $parameters];

            return array_shift($answers) ?? [];
        });

        return $graph;
    }

    private function exporter(?string $jpegUrl): SocialImageExporter
    {
        $exporter = $this->createStub(SocialImageExporter::class);
        $exporter->method('jpegUrl')->willReturn($jpegUrl);

        return $exporter;
    }

    private function config(): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap([
            ['social-meta-page-id', '55'],
            ['social-meta-instagram-id', '77'],
        ]);

        return $configService;
    }

    private function content(): SocialContent
    {
        return new SocialContent('1', 'Lac', 'https://example.org/lac', imageAlt: 'Le lac au matin');
    }

    private function picture(string $name): PostMedia
    {
        return new PostMedia('/var/medias/' . $name . '.jpg', 'https://example.org/medias/' . $name . '.jpg', 'image/jpeg', alt: 'Alt ' . $name);
    }

    private function video(): PostMedia
    {
        return new PostMedia('/var/medias/v.mp4', 'https://example.org/medias/v.mp4', 'video/mp4', duration: 12.0);
    }

    public function testFacebookMediaRules(): void
    {
        $rules = new FacebookPublisher($this->graph([]), $this->exporter(null), $this->config())->getMediaRules();

        $this->assertSame([10, true, false, false, ['image/jpeg', 'image/png', 'image/gif'], 10000000, ['video/mp4', 'video/quicktime']], [$rules->maxImages, $rules->video, $rules->mix, $rules->required, $rules->imageTypes, $rules->maxImageBytes, $rules->videoTypes]);
    }

    public function testInstagramMediaRules(): void
    {
        $rules = new InstagramPublisher($this->graph([]), $this->exporter(null), $this->config(), 0)->getMediaRules();

        $this->assertSame(
            [10, true, true, true, ['image/jpeg'], 8000000, 0.8, 1.91, ['video/mp4', 'video/quicktime'], 300000000, 3.0, 900.0],
            [$rules->maxImages, $rules->video, $rules->mix, $rules->required, $rules->imageTypes, $rules->maxImageBytes, $rules->minRatio, $rules->maxRatio, $rules->videoTypes, $rules->maxVideoBytes, $rules->minDuration, $rules->maxDuration],
        );
    }

    public function testFacebookPostsAPhotoWithItsCaptionOnThePage(): void
    {
        $id = new FacebookPublisher($this->graph([['id' => '9', 'post_id' => '55_9']]), $this->exporter('https://example.org/medias/social/a.jpg'), $this->config())->publish('Texte', $this->content());

        $this->assertSame('55_9', $id);
        $this->assertSame('/55/photos', $this->calls[0]['path']);
        $this->assertSame(['url' => 'https://example.org/medias/social/a.jpg', 'caption' => 'Texte', 'access_token' => 'page-token'], $this->calls[0]['parameters']);
    }

    // A post written with no link sends its text alone, no empty link Facebook would refuse
    public function testFacebookPostsTheTextAloneWhenThereIsNoLink(): void
    {
        $preview = new FacebookPublisher($this->graph([]), $this->exporter(null), $this->config())->preview('Texte', new SocialContent('42', 'Lac', ''));

        $this->assertSame(['path' => '/55/feed', 'parameters' => ['message' => 'Texte']], $preview);
    }

    // Without an image, a link: Facebook draws the page's own preview
    public function testFacebookPostsALinkWhenTheContentHasNoImage(): void
    {
        $preview = new FacebookPublisher($this->graph([]), $this->exporter(null), $this->config())->preview('Texte', $this->content());

        $this->assertSame(['path' => '/55/feed', 'parameters' => ['message' => 'Texte', 'link' => 'https://example.org/lac']], $preview);
    }

    // Created, waited for while Instagram processes the image, then published
    public function testInstagramPublishesTheContainerOnceItIsProcessed(): void
    {
        $graph = $this->graph([['id' => 'c1'], ['status_code' => 'IN_PROGRESS'], ['status_code' => 'FINISHED'], ['id' => 'm1']]);

        $id = new InstagramPublisher($graph, $this->exporter('https://example.org/medias/social/a.jpg'), $this->config(), 0)->publish('Texte', $this->content());

        $this->assertSame('m1', $id);
        $this->assertSame(['image_url' => 'https://example.org/medias/social/a.jpg', 'caption' => 'Texte', 'alt_text' => 'Le lac au matin', 'access_token' => 'page-token'], $this->calls[0]['parameters']);
        $this->assertSame('/77/media_publish', $this->calls[3]['path']);
        $this->assertSame('c1', $this->calls[3]['parameters']['creation_id']);
    }

    public function testInstagramSaysWhenItCouldNotProcessTheImage(): void
    {
        $this->expectExceptionMessageIsOrContains('could not process the image (ERROR)');

        new InstagramPublisher($this->graph([['id' => 'c1'], ['status_code' => 'ERROR']]), $this->exporter('https://example.org/a.jpg'), $this->config(), 0)->publish('Texte', $this->content());
    }

    public function testInstagramTakesNoPostWithoutAnImage(): void
    {
        $this->expectExceptionMessageIsOrContains('only takes posts with an image');

        new InstagramPublisher($this->graph([]), $this->exporter(null), $this->config(), 0)->publish('Texte', $this->content());
    }

    // The post's own picture replaces the content's image
    public function testFacebookPostsTheOwnPictureRatherThanTheContentImage(): void
    {
        $preview = new FacebookPublisher($this->graph([]), $this->exporter('https://example.org/medias/social/a.jpg'), $this->config())->preview('Texte', $this->content(), [$this->picture('p1')]);

        $this->assertSame(['path' => '/55/photos', 'parameters' => ['url' => 'https://example.org/medias/p1.jpg', 'caption' => 'Texte']], $preview);
    }

    // Several pictures are uploaded unpublished, then attached in their order to one post
    public function testFacebookAttachesSeveralPhotosToOnePost(): void
    {
        $graph = $this->graph([['id' => 'f1'], ['id' => 'f2'], ['id' => '55_9']]);

        $id = new FacebookPublisher($graph, $this->exporter(null), $this->config())->publish('Texte', $this->content(), [$this->picture('p1'), $this->picture('p2')]);

        $this->assertSame('55_9', $id);
        $this->assertSame(['/55/photos', '/55/photos', '/55/feed'], array_column($this->calls, 'path'));
        $this->assertSame(['url' => 'https://example.org/medias/p2.jpg', 'published' => 'false', 'access_token' => 'page-token'], $this->calls[1]['parameters']);
        $this->assertSame(['message' => 'Texte', 'attached_media[0]' => '{"media_fbid":"f1"}', 'attached_media[1]' => '{"media_fbid":"f2"}', 'access_token' => 'page-token'], $this->calls[2]['parameters']);
    }

    public function testFacebookPostsAVideoFromItsUrl(): void
    {
        $id = new FacebookPublisher($this->graph([['id' => 'v9']]), $this->exporter(null), $this->config())->publish('Texte', $this->content(), [$this->video()]);

        $this->assertSame('v9', $id);
        $this->assertSame('/55/videos', $this->calls[0]['path']);
        $this->assertSame(['file_url' => 'https://example.org/medias/v.mp4', 'description' => 'Texte', 'access_token' => 'page-token'], $this->calls[0]['parameters']);
    }

    // The post's own picture is framed within Instagram's ratios, as the content's image is
    public function testInstagramFramesTheOwnPicture(): void
    {
        $exporter = $this->createMock(SocialImageExporter::class);
        $exporter->expects($this->once())->method('jpegUrl')
            ->with($this->callback(fn (SocialContent $content): bool => '/var/medias/p1.jpg' === $content->imagePath), 0.8, 1.91)
            ->willReturn('https://example.org/medias/social/p1.jpg');

        $preview = new InstagramPublisher($this->graph([]), $exporter, $this->config(), 0)->preview('Texte', $this->content(), [$this->picture('p1')]);

        $this->assertSame(['path' => '/77/media', 'parameters' => ['image_url' => 'https://example.org/medias/social/p1.jpg', 'caption' => 'Texte', 'alt_text' => 'Alt p1']], $preview);
    }

    // A video alone goes out as a reel, published once processed
    public function testInstagramPublishesAVideoAsAReel(): void
    {
        $graph = $this->graph([['id' => 'c1'], ['status_code' => 'IN_PROGRESS'], ['status_code' => 'FINISHED'], ['id' => 'm1']]);

        $id = new InstagramPublisher($graph, $this->exporter(null), $this->config(), 0)->publish('Texte', $this->content(), [$this->video()]);

        $this->assertSame('m1', $id);
        $this->assertSame(['media_type' => 'REELS', 'video_url' => 'https://example.org/medias/v.mp4', 'caption' => 'Texte', 'access_token' => 'page-token'], $this->calls[0]['parameters']);
        $this->assertSame('c1', $this->calls[3]['parameters']['creation_id']);
    }

    public function testInstagramSaysWhenItCouldNotProcessTheVideo(): void
    {
        $this->expectExceptionMessageIsOrContains('could not process the video (ERROR)');

        new InstagramPublisher($this->graph([['id' => 'c1'], ['status_code' => 'ERROR']]), $this->exporter(null), $this->config(), 0)->publish('Texte', $this->content(), [$this->video()]);
    }

    // Several medias: each a child, a video child waited for, then the carousel waited for and published
    public function testInstagramPublishesSeveralMediasAsACarousel(): void
    {
        $graph = $this->graph([['id' => 'k1'], ['id' => 'k2'], ['status_code' => 'FINISHED'], ['id' => 'c1'], ['status_code' => 'FINISHED'], ['id' => 'm1']]);

        $id = new InstagramPublisher($graph, $this->exporter('https://example.org/medias/social/p1.jpg'), $this->config(), 0)->publish('Texte', $this->content(), [$this->picture('p1'), $this->video()]);

        $this->assertSame('m1', $id);
        $this->assertSame(['/77/media', '/77/media', '/k2', '/77/media', '/c1', '/77/media_publish'], array_column($this->calls, 'path'));
        $this->assertSame(['is_carousel_item' => 'true', 'image_url' => 'https://example.org/medias/social/p1.jpg', 'access_token' => 'page-token'], $this->calls[0]['parameters']);
        $this->assertSame(['is_carousel_item' => 'true', 'media_type' => 'VIDEO', 'video_url' => 'https://example.org/medias/v.mp4', 'access_token' => 'page-token'], $this->calls[1]['parameters']);
        $this->assertSame(['media_type' => 'CAROUSEL', 'caption' => 'Texte', 'children' => 'k1,k2', 'access_token' => 'page-token'], $this->calls[3]['parameters']);
        $this->assertSame('c1', $this->calls[5]['parameters']['creation_id']);
    }
}
