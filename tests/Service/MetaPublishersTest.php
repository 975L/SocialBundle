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
use c975L\SocialBundle\Service\InstagramLoginClient;
use c975L\SocialBundle\Service\InstagramPublisher;
use c975L\SocialBundle\Service\MetaGraphClient;
use c975L\SocialBundle\Service\SocialImageExporter;
use c975L\UiBundle\Model\SocialContent;
use PHPUnit\Framework\TestCase;

class MetaPublishersTest extends TestCase
{
    /** @var list<array{method: string, path: string, parameters: array<string, string>}> */
    private array $calls = [];

    // Each call kept in $calls, the next of $answers given back
    /** @param list<array<string, mixed>>|null $answers */
    private function recordedAnswers(?array &$answers): \Closure
    {
        return function (string $method, string $path, array $parameters) use (&$answers): array {
            $this->calls[] = ['method' => $method, 'path' => $path, 'parameters' => $parameters];

            return array_shift($answers) ?? [];
        };
    }

    /**
     * @param list<array<string, mixed>> $answers
     */
    private function graph(array $answers): MetaGraphClient
    {
        $graph = $this->createStub(MetaGraphClient::class);
        $graph->method('pageToken')->willReturn('page-token');
        $graph->method('request')->willReturnCallback($this->recordedAnswers($answers));

        return $graph;
    }

    // No account connected by Instagram Login, the Page's being the one used, unless its answers are given
    /** @param list<array<string, mixed>>|null $answers */
    private function login(?array $answers = null): InstagramLoginClient
    {
        $login = $this->createStub(InstagramLoginClient::class);
        $login->method('isConnected')->willReturn(null !== $answers);
        $login->method('accessToken')->willReturn('ig-token');
        $login->method('accountId')->willReturn('88');
        $login->method('request')->willReturnCallback($this->recordedAnswers($answers));

        return $login;
    }

    private function exporter(?string $jpegUrl): SocialImageExporter
    {
        $exporter = $this->createStub(SocialImageExporter::class);
        $exporter->method('jpegUrl')->willReturn($jpegUrl);
        $exporter->method('storyUrl')->willReturn(null === $jpegUrl ? null : str_replace('.jpg', '-story.jpg', $jpegUrl));

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
        $rules = new InstagramPublisher($this->graph([]), $this->login(), $this->exporter(null), $this->config(), 0)->getMediaRules();

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

        $id = new InstagramPublisher($graph, $this->login(), $this->exporter('https://example.org/medias/social/a.jpg'), $this->config(), 0)->publish('Texte', $this->content());

        $this->assertSame('m1', $id);
        $this->assertSame(['image_url' => 'https://example.org/medias/social/a.jpg', 'caption' => 'Texte', 'alt_text' => 'Le lac au matin', 'access_token' => 'page-token'], $this->calls[0]['parameters']);
        $this->assertSame('/77/media_publish', $this->calls[3]['path']);
        $this->assertSame('c1', $this->calls[3]['parameters']['creation_id']);
    }

    // A Page story: the vertical picture uploaded unpublished, then made a story
    public function testFacebookPublishesAPhotoStory(): void
    {
        $id = new FacebookPublisher($this->graph([['id' => '9'], ['success' => true, 'post_id' => '55_10']]), $this->exporter('https://example.org/medias/social/a.jpg'), $this->config())->story($this->content());

        $this->assertSame('55_10', $id);
        $this->assertSame(['/55/photos', '/55/photo_stories'], array_column($this->calls, 'path'));
        $this->assertSame(['url' => 'https://example.org/medias/social/a-story.jpg', 'published' => 'false', 'access_token' => 'page-token'], $this->calls[0]['parameters']);
        $this->assertSame('9', $this->calls[1]['parameters']['photo_id']);
    }

    // A 200 that does not say the story went out leaves it to be sent again
    public function testFacebookSaysWhenTheStoryWasNotPublished(): void
    {
        $this->expectExceptionMessageIsOrContains('did not publish the story');

        new FacebookPublisher($this->graph([['id' => '9'], ['success' => false]]), $this->exporter('https://example.org/medias/social/a.jpg'), $this->config())->story($this->content());
    }

    // No picture, no story
    public function testAStoryTakesAPicture(): void
    {
        $this->expectExceptionMessageIsOrContains('A story takes a picture');

        new InstagramPublisher($this->graph([]), $this->login(), $this->exporter(null), $this->config(), 0)->story($this->content());
    }

    // An Instagram story: a STORIES container of the vertical picture, no caption, waited for, then published
    public function testInstagramPublishesAStory(): void
    {
        $graph = $this->graph([['id' => 'c1'], ['status_code' => 'FINISHED'], ['id' => 's1']]);

        $id = new InstagramPublisher($graph, $this->login(), $this->exporter('https://example.org/medias/social/a.jpg'), $this->config(), 0)->story($this->content());

        $this->assertSame('s1', $id);
        $this->assertSame(['media_type' => 'STORIES', 'image_url' => 'https://example.org/medias/social/a-story.jpg', 'access_token' => 'page-token'], $this->calls[0]['parameters']);
        $this->assertSame('/77/media_publish', $this->calls[2]['path']);
    }

    // A story Meta says is not available yet past FINISHED is published again rather than refused
    public function testInstagramPublishesAgainAMediaNotAvailableYet(): void
    {
        $answers = [['id' => 'c1'], ['status_code' => 'FINISHED'], null, ['id' => 's1']];
        $graph = $this->createStub(MetaGraphClient::class);
        $graph->method('pageToken')->willReturn('page-token');
        $graph->method('request')->willReturnCallback(function (string $method, string $path, array $parameters) use (&$answers): array {
            $this->calls[] = ['method' => $method, 'path' => $path, 'parameters' => $parameters];

            return array_shift($answers) ?? throw new \RuntimeException('Meta refused ' . $path . ': Media ID is not available');
        });

        $id = new InstagramPublisher($graph, $this->login(), $this->exporter('https://example.org/medias/social/a.jpg'), $this->config(), 0)->story($this->content());

        $this->assertSame('s1', $id);
        $this->assertSame(['/77/media', '/c1', '/77/media_publish', '/77/media_publish'], array_column($this->calls, 'path'));
    }

    // Any other refusal of the publication is not asked again
    public function testInstagramDoesNotPublishAgainAfterAnotherRefusal(): void
    {
        $answers = [['id' => 'c1'], ['status_code' => 'FINISHED']];
        $graph = $this->createStub(MetaGraphClient::class);
        $graph->method('pageToken')->willReturn('page-token');
        $graph->method('request')->willReturnCallback(function (string $method, string $path) use (&$answers): array {
            $this->calls[] = ['method' => $method, 'path' => $path, 'parameters' => []];

            return array_shift($answers) ?? throw new \RuntimeException('Meta refused ' . $path . ': (#10) permission');
        });

        $this->expectExceptionMessage('(#10) permission');

        try {
            new InstagramPublisher($graph, $this->login(), $this->exporter('https://example.org/medias/social/a.jpg'), $this->config(), 0)->story($this->content());
        } finally {
            $this->assertCount(3, $this->calls);
        }
    }

    // A post's video goes as an Instagram story through its url, waited for as a video, then published
    public function testInstagramPublishesAVideoStory(): void
    {
        $graph = $this->graph([['id' => 'c1'], ['status_code' => 'FINISHED'], ['id' => 's1']]);
        $video = new PostMedia('/var/medias/card.mp4', 'https://example.org/medias/card.mp4', 'video/mp4', width: 1080, height: 1920, duration: 7.0);

        $id = new InstagramPublisher($graph, $this->login(), $this->exporter(null), $this->config(), 0)->story($this->content(), $video);

        $this->assertSame('s1', $id);
        $this->assertSame(['media_type' => 'STORIES', 'video_url' => 'https://example.org/medias/card.mp4', 'access_token' => 'page-token'], $this->calls[0]['parameters']);
        $this->assertSame('/77/media_publish', $this->calls[2]['path']);
    }

    // A post's video goes as a Facebook Page story in the reel's phases, on the video stories, with no text
    public function testFacebookPublishesAVideoStory(): void
    {
        $graph = $this->graph([['video_id' => 'v1', 'upload_url' => 'https://rupload.example/v1'], ['success' => true, 'post_id' => '55_v1']]);
        $video = new PostMedia('/var/medias/card.mp4', 'https://example.org/medias/card.mp4', 'video/mp4', width: 1080, height: 1920, duration: 7.0);

        $id = new FacebookPublisher($graph, $this->exporter(null), $this->config())->story($this->content(), $video);

        $this->assertSame('55_v1', $id);
        $this->assertSame(['/55/video_stories', '/55/video_stories'], array_column($this->calls, 'path'));
        $this->assertSame(['upload_phase' => 'finish', 'video_id' => 'v1', 'access_token' => 'page-token'], $this->calls[1]['parameters']);
    }

    // A video story neither said successful nor given an id is left to be sent again
    public function testFacebookSaysWhenTheVideoStoryWasNotPublished(): void
    {
        $this->expectExceptionMessageIsOrContains('did not publish the story');
        $video = new PostMedia('/var/medias/card.mp4', 'https://example.org/medias/card.mp4', 'video/mp4', width: 1080, height: 1920, duration: 7.0);

        new FacebookPublisher($this->graph([['video_id' => 'v1'], ['success' => false]]), $this->exporter(null), $this->config())->story($this->content(), $video);
    }

    public function testInstagramSaysWhenItCouldNotProcessTheImage(): void
    {
        $this->expectExceptionMessageIsOrContains('could not process the image (ERROR)');

        new InstagramPublisher($this->graph([['id' => 'c1'], ['status_code' => 'ERROR']]), $this->login(), $this->exporter('https://example.org/a.jpg'), $this->config(), 0)->publish('Texte', $this->content());
    }

    public function testInstagramTakesNoPostWithoutAnImage(): void
    {
        $this->expectExceptionMessageIsOrContains('only takes posts with an image');

        new InstagramPublisher($this->graph([]), $this->login(), $this->exporter(null), $this->config(), 0)->publish('Texte', $this->content());
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

    // A vertical video of 90 s at most goes out as a reel, in Meta's three phases: started, its file sent, published with its text
    public function testFacebookPostsAVerticalShortVideoAsAReel(): void
    {
        $graph = $this->graph([['video_id' => 'r1', 'upload_url' => 'https://rupload.example/r1'], ['success' => true, 'post_id' => '55_r1']]);
        $reel = new PostMedia('/var/medias/r.mp4', 'https://example.org/medias/r.mp4', 'video/mp4', width: 1080, height: 1920, duration: 25.0);

        $id = new FacebookPublisher($graph, $this->exporter(null), $this->config())->publish('Texte', $this->content(), [$reel]);

        $this->assertSame('55_r1', $id);
        $this->assertSame(['/55/video_reels', '/55/video_reels'], array_column($this->calls, 'path'));
        $this->assertSame(['upload_phase' => 'start', 'access_token' => 'page-token'], $this->calls[0]['parameters']);
        $this->assertSame(['upload_phase' => 'finish', 'video_id' => 'r1', 'video_state' => 'PUBLISHED', 'description' => 'Texte', 'access_token' => 'page-token'], $this->calls[1]['parameters']);
    }

    // A finish Meta accepted without success yet is a reel still processing: published, never sent twice
    public function testFacebookKeepsAReelStillProcessing(): void
    {
        $reel = new PostMedia('/var/medias/r.mp4', 'https://example.org/medias/r.mp4', 'video/mp4', width: 1080, height: 1920, duration: 25.0);
        $this->assertSame('r1', new FacebookPublisher($this->graph([['video_id' => 'r1', 'upload_url' => 'https://rupload.example/r1'], ['success' => false]]), $this->exporter(null), $this->config())->publish('Texte', $this->content(), [$reel]));
    }

    // A landscape video, a long one, or one never measured stays a plain Page video
    public function testFacebookKeepsOtherVideosAsPlainVideos(): void
    {
        $publisher = new FacebookPublisher($this->graph([]), $this->exporter(null), $this->config());
        $landscape = new PostMedia('/v.mp4', 'https://example.org/v.mp4', 'video/mp4', width: 1920, height: 1080, duration: 25.0);
        $long = new PostMedia('/v.mp4', 'https://example.org/v.mp4', 'video/mp4', width: 1080, height: 1920, duration: 120.0);

        foreach ([$landscape, $long, $this->video()] as $video) {
            $this->assertSame('/55/videos', $publisher->preview('Texte', $this->content(), [$video])['path']);
        }
    }

    // The content's image is framed to 4:5, so the grid cuts next to nothing off it
    public function testInstagramFramesTheContentImageForTheGrid(): void
    {
        $exporter = $this->createMock(SocialImageExporter::class);
        $exporter->expects($this->once())->method('jpegUrl')->with($this->isInstanceOf(SocialContent::class), 0.8, 0.8)->willReturn('https://example.org/medias/social/a.jpg');

        new InstagramPublisher($this->graph([['id' => 'c1'], ['status_code' => 'FINISHED'], ['id' => 'm1']]), $this->login(), $exporter, $this->config(), 0)->publish('Texte', $this->content());
    }

    // The post's own picture is framed to 4:5, as the content's image is
    public function testInstagramFramesTheOwnPicture(): void
    {
        $exporter = $this->createMock(SocialImageExporter::class);
        $exporter->expects($this->once())->method('jpegUrl')
            ->with($this->callback(fn (SocialContent $content): bool => '/var/medias/p1.jpg' === $content->imagePath), 0.8, 0.8)
            ->willReturn('https://example.org/medias/social/p1.jpg');

        new InstagramPublisher($this->graph([['id' => 'c1'], ['status_code' => 'FINISHED'], ['id' => 'm1']]), $this->login(), $exporter, $this->config(), 0)->publish('Texte', $this->content(), [$this->picture('p1')]);

        $this->assertSame(['image_url' => 'https://example.org/medias/social/p1.jpg', 'caption' => 'Texte', 'alt_text' => 'Alt p1', 'access_token' => 'page-token'], $this->calls[0]['parameters']);
    }

    // A preview shows the source pictures, no JPEG copy written
    public function testAnInstagramPreviewWritesNoCopy(): void
    {
        $exporter = $this->createMock(SocialImageExporter::class);
        $exporter->expects($this->never())->method('jpegUrl');

        $preview = new InstagramPublisher($this->graph([]), $this->login(), $exporter, $this->config(), 0)->preview('Texte', $this->content(), [$this->picture('p1')]);

        $this->assertSame('/var/medias/p1.jpg', $preview['parameters']['image_url']);
    }

    // A video alone goes out as a reel, published once processed
    public function testInstagramPublishesAVideoAsAReel(): void
    {
        $graph = $this->graph([['id' => 'c1'], ['status_code' => 'IN_PROGRESS'], ['status_code' => 'FINISHED'], ['id' => 'm1']]);

        $id = new InstagramPublisher($graph, $this->login(), $this->exporter(null), $this->config(), 0)->publish('Texte', $this->content(), [$this->video()]);

        $this->assertSame('m1', $id);
        $this->assertSame(['media_type' => 'REELS', 'video_url' => 'https://example.org/medias/v.mp4', 'caption' => 'Texte', 'thumb_offset' => '2000', 'access_token' => 'page-token'], $this->calls[0]['parameters']);
        $this->assertSame('c1', $this->calls[3]['parameters']['creation_id']);
    }

    public function testInstagramSaysWhenItCouldNotProcessTheVideo(): void
    {
        $this->expectExceptionMessageIsOrContains('could not process the video (ERROR)');

        new InstagramPublisher($this->graph([['id' => 'c1'], ['status_code' => 'ERROR']]), $this->login(), $this->exporter(null), $this->config(), 0)->publish('Texte', $this->content(), [$this->video()]);
    }

    // Several medias: each a child, a video child waited for, then the carousel waited for and published
    public function testInstagramPublishesSeveralMediasAsACarousel(): void
    {
        $graph = $this->graph([['id' => 'k1'], ['id' => 'k2'], ['status_code' => 'FINISHED'], ['id' => 'c1'], ['status_code' => 'FINISHED'], ['id' => 'm1']]);

        $id = new InstagramPublisher($graph, $this->login(), $this->exporter('https://example.org/medias/social/p1.jpg'), $this->config(), 0)->publish('Texte', $this->content(), [$this->picture('p1'), $this->video()]);

        $this->assertSame('m1', $id);
        $this->assertSame(['/77/media', '/77/media', '/k2', '/77/media', '/c1', '/77/media_publish'], array_column($this->calls, 'path'));
        $this->assertSame(['is_carousel_item' => 'true', 'image_url' => 'https://example.org/medias/social/p1.jpg', 'access_token' => 'page-token'], $this->calls[0]['parameters']);
        $this->assertSame(['is_carousel_item' => 'true', 'media_type' => 'VIDEO', 'video_url' => 'https://example.org/medias/v.mp4', 'access_token' => 'page-token'], $this->calls[1]['parameters']);
        $this->assertSame(['media_type' => 'CAROUSEL', 'caption' => 'Texte', 'children' => 'k1,k2', 'access_token' => 'page-token'], $this->calls[3]['parameters']);
        $this->assertSame('c1', $this->calls[5]['parameters']['creation_id']);
    }

    // The count needs no permission beyond the ones a site already granted
    public function testInstagramReadsTheMediasCommentCount(): void
    {
        $comments = new InstagramPublisher($this->graph([['comments_count' => 2, 'permalink' => 'https://www.instagram.com/p/x/']]), $this->login(), $this->exporter(null), $this->config(), 0)->commentCount('77');

        $this->assertSame(2, $comments->count);
        $this->assertSame('https://www.instagram.com/p/x/', $comments->url);
        $this->assertSame('comments_count,permalink', $this->calls[0]['parameters']['fields']);
    }

    // Only the newest are kept, whatever order Meta answers in
    public function testInstagramReadsTheNewestComments(): void
    {
        $comments = new InstagramPublisher($this->graph([['data' => [
            ['username' => 'ann', 'text' => 'Old', 'timestamp' => '2026-11-01T10:00:00+0000'],
            ['username' => 'bob', 'text' => 'New', 'timestamp' => '2026-11-02T10:00:00+0000'],
            ['username' => 'cid', 'text' => 'Older', 'timestamp' => '2026-10-01T10:00:00+0000'],
        ]]]), $this->login(), $this->exporter(null), $this->config(), 0)->latestComments('77', 2);

        $this->assertSame([['bob', 'New'], ['ann', 'Old']], array_map(static fn ($comment): array => [$comment->author, $comment->text], $comments));
        $this->assertSame('/77/comments', $this->calls[0]['path']);
        $this->assertSame('username,text,timestamp', $this->calls[0]['parameters']['fields']);
    }

    // An account connected by Instagram Login takes over from the Page's: its id, its token and its own host, without the Page's client being called
    public function testAnAccountConnectedWithoutAPageIsTheOnePostedOn(): void
    {
        $graph = $this->createMock(MetaGraphClient::class);
        $graph->expects($this->never())->method('request');

        $id = new InstagramPublisher($graph, $this->login([['id' => 'c1'], ['status_code' => 'FINISHED'], ['id' => 'm1']]), $this->exporter('https://example.org/medias/social/a.jpg'), $this->config(), 0)->publish('Texte', $this->content());

        $this->assertSame('m1', $id);
        $this->assertSame(['/88/media', '/c1', '/88/media_publish'], array_column($this->calls, 'path'));
        $this->assertSame(['ig-token', 'ig-token', 'ig-token'], array_map(static fn (array $call): string => $call['parameters']['access_token'], $this->calls));
    }

    // Neither a Page nor an account connected by Instagram Login: nothing goes out, one of the two being enough
    public function testInstagramIsConfiguredByEitherConnection(): void
    {
        $noPage = $this->createStub(ConfigServiceInterface::class);
        $noToken = $this->createStub(MetaGraphClient::class);

        $this->assertFalse(new InstagramPublisher($noToken, $this->login(), $this->exporter(null), $noPage, 0)->isConfigured());
        $this->assertTrue(new InstagramPublisher($noToken, $this->login([]), $this->exporter(null), $noPage, 0)->isConfigured());
        $this->assertTrue(new InstagramPublisher($this->graph([]), $this->login(), $this->exporter(null), $this->config(), 0)->isConfigured());
    }
}
