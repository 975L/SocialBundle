<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Contract\NetworkPublisherInterface;
use c975L\SocialBundle\Model\MediaRules;
use c975L\SocialBundle\Model\PostMedia;
use c975L\UiBundle\Model\SocialContent;

// Posts on the site's Facebook Page, never on a personal profile: the post's own pictures or video when it has some, else a photo with its text when the content has an image, a link otherwise - Facebook then drawing the page's own preview
class FacebookPublisher implements NetworkPublisherInterface
{
    // What a Page post accepts
    private const int MAX_LENGTH = 63206;

    // What Meta takes as a Page reel (developers.facebook.com/docs/video-api/guides/reels-publishing): 9:16, 540 x 960 at least, 3 to 90 s - a video outside them goes out as a plain Page video
    private const float REEL_MIN_RATIO = 0.5;
    private const float REEL_MAX_RATIO = 0.6;
    private const int REEL_MIN_WIDTH = 540;
    private const float REEL_MIN_DURATION = 3.0;
    private const float REEL_MAX_DURATION = 90.0;

    public function __construct(
        private readonly MetaGraphClient $metaGraphClient,
        private readonly SocialImageExporter $imageExporter,
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    public function getName(): string
    {
        return 'facebook';
    }

    public function isConfigured(): bool
    {
        return '' !== $this->pageId() && null !== $this->metaGraphClient->pageToken();
    }

    public function getMaxLength(): int
    {
        return self::MAX_LENGTH;
    }

    // Graph page/photos takes a picture under 10 MB (its French page says 4 MB); the count of a multi-photo post and the video limits are undocumented, 10 pictures kept as a sane cap
    public function getMediaRules(): MediaRules
    {
        return new MediaRules(
            maxImages: 10,
            video: true,
            mix: false,
            required: false,
            imageTypes: ['image/jpeg', 'image/png', 'image/gif'],
            maxImageBytes: 10000000,
            videoTypes: ['video/mp4', 'video/quicktime'],
        );
    }

    // The post's own id ("pageid_postid", a video's id for a video or a reel), its public address being facebook.com/ followed by it. Several pictures are first uploaded unpublished, then attached to one post
    public function publish(string $text, SocialContent $content, array $medias = []): string
    {
        $token = ['access_token' => (string) $this->metaGraphClient->pageToken()];
        $request = $this->request($text, $content, $medias);
        if (isset($request['reel'])) {
            return $this->publishReel($request['path'], $request['reel'], $token['access_token']);
        }

        // Each uploaded picture becomes one "attached_media[i]", the JSON Graph expects in each
        $parameters = $request['parameters'];
        foreach ($request['uploads'] ?? [] as $index => $upload) {
            $photo = $this->metaGraphClient->request('POST', $upload['path'], [...$upload['parameters'], ...$token]);
            $parameters['attached_media[' . $index . ']'] = (string) json_encode(['media_fbid' => (string) $photo['id']]);
        }

        $posted = $this->metaGraphClient->request('POST', $request['path'], [...$parameters, ...$token]);

        return (string) ($posted['post_id'] ?? $posted['id']);
    }

    public function preview(string $text, SocialContent $content, array $medias = []): array
    {
        return $this->request($text, $content, $medias);
    }

    // A reel in Meta's three phases: a session started on the Page, the video handed over by its address, then published with its text
    /** @param array{file_url: string, description: string} $reel */
    private function publishReel(string $path, array $reel, string $token): string
    {
        $started = $this->metaGraphClient->request('POST', $path, ['upload_phase' => 'start', 'access_token' => $token]);
        if (!isset($started['video_id'])) {
            throw new \RuntimeException('Meta did not open a reel upload.');
        }

        $videoId = (string) $started['video_id'];
        $this->metaGraphClient->uploadHosted((string) ($started['upload_url'] ?? 'https://rupload.facebook.com/video-upload/' . MetaGraphClient::VERSION . '/' . $videoId), $reel['file_url'], $token);
        $finished = $this->metaGraphClient->request('POST', $path, ['upload_phase' => 'finish', 'video_id' => $videoId, 'video_state' => 'PUBLISHED', 'description' => $reel['description'], 'access_token' => $token]);

        // A 200 may still say the reel did not go out (being processed, blocked on rights): left in error, so publishing tries it again
        if (true !== ($finished['success'] ?? null)) {
            throw new \RuntimeException('Meta did not publish the reel.');
        }

        return (string) ($finished['post_id'] ?? $videoId);
    }

    // Whether a video is one Meta takes as a reel, read off what was measured of it - an unmeasured one staying a plain video
    private static function isReel(PostMedia $media): bool
    {
        $ratio = $media->ratio();

        return null !== $ratio && null !== $media->duration
            && $ratio >= self::REEL_MIN_RATIO && $ratio <= self::REEL_MAX_RATIO
            && $media->width >= self::REEL_MIN_WIDTH
            && $media->duration >= self::REEL_MIN_DURATION && $media->duration <= self::REEL_MAX_DURATION;
    }

    // The calls publish() makes, token aside. "caption" is the text of a photo, "description" that of a video, "message" that of a post, its "link" left out for a post written with none
    /**
     * @param list<PostMedia> $medias
     *
     * @return array{path: string, parameters: array<string, string>, uploads?: list<array{path: string, parameters: array<string, string>}>, reel?: array{file_url: string, description: string}}
     */
    private function request(string $text, SocialContent $content, array $medias): array
    {
        $page = '/' . $this->pageId();

        // The post's video, alone as Facebook takes it - a vertical one of 90 s at most as a reel, the format Facebook shows to people who do not follow the Page
        foreach ($medias as $media) {
            if ($media->isVideo() && self::isReel($media)) {
                return ['path' => $page . '/video_reels', 'parameters' => ['upload_phase' => 'start'], 'reel' => ['file_url' => $media->url, 'description' => $text]];
            }
            if ($media->isVideo()) {
                return ['path' => $page . '/videos', 'parameters' => ['file_url' => $media->url, 'description' => $text]];
            }
        }

        // Several pictures, uploaded unpublished then attached to one post
        if (\count($medias) > 1) {
            $uploads = array_map(fn (PostMedia $media): array => ['path' => $page . '/photos', 'parameters' => ['url' => $media->url, 'published' => 'false']], $medias);

            return ['path' => $page . '/feed', 'parameters' => ['message' => $text], 'uploads' => $uploads];
        }

        // One picture, the post's own or the content's
        $imageUrl = [] === $medias ? $this->imageExporter->jpegUrl($content) : $medias[0]->url;

        return null === $imageUrl
            ? ['path' => $page . '/feed', 'parameters' => array_filter(['message' => $text, 'link' => $content->url])]
            : ['path' => $page . '/photos', 'parameters' => ['url' => $imageUrl, 'caption' => $text]];
    }

    private function pageId(): string
    {
        return trim((string) $this->configService->get('social-meta-page-id'));
    }
}
