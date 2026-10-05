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

// Posts on the Instagram professional account linked to the site's Facebook Page, with the Page's own token. Instagram takes nothing without a picture or a video: one picture as a JPEG within its range of ratios (see SocialImageExporter), one video as a reel, several as a carousel. A link in a caption is not clickable on Instagram: a template meant for it says so ("link in bio") rather than relying on the url
class InstagramPublisher implements NetworkPublisherInterface
{
    // What a caption accepts
    private const int MAX_LENGTH = 2200;

    // The range of width/height ratios Instagram accepts, from 4:5 to 1.91:1
    private const float MIN_RATIO = 0.8;
    private const float MAX_RATIO = 1.91;

    // Instagram fetches and processes each media before it can be published: asked again this many times, this many seconds apart - a video taking minutes where a picture takes seconds (a sleep counting for nothing in PHP's time limit)
    private const int STATUS_ATTEMPTS = 10;
    private const int VIDEO_STATUS_ATTEMPTS = 100;

    public function __construct(
        private readonly MetaGraphClient $metaGraphClient,
        private readonly SocialImageExporter $imageExporter,
        private readonly ConfigServiceInterface $configService,
        private readonly int $statusDelay = 3,
    ) {
    }

    public function getName(): string
    {
        return 'instagram';
    }

    public function isConfigured(): bool
    {
        return '' !== $this->instagramId() && null !== $this->metaGraphClient->pageToken();
    }

    public function getMaxLength(): int
    {
        return self::MAX_LENGTH;
    }

    // developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-user/media: JPEG only, 8 MB, 4:5 to 1.91:1; a carousel of up to 10 pictures and videos mixed; a REELS video of 3 s to 15 min and 300 MB
    public function getMediaRules(): MediaRules
    {
        return new MediaRules(
            maxImages: 10,
            video: true,
            mix: true,
            required: true,
            imageTypes: ['image/jpeg'],
            maxImageBytes: 8000000,
            minRatio: self::MIN_RATIO,
            maxRatio: self::MAX_RATIO,
            videoTypes: ['video/mp4', 'video/quicktime'],
            maxVideoBytes: 300000000,
            minDuration: 3.0,
            maxDuration: 900.0,
        );
    }

    // The media's id: a carousel's children are created first, each video one waited for, then the container is created, waited for until Instagram has processed it, and published
    public function publish(string $text, SocialContent $content, array $medias = []): string
    {
        $token = ['access_token' => (string) $this->metaGraphClient->pageToken()];
        $request = $this->request($text, $content, $medias);

        // The carousel's children, their ids joined in "children"
        $parameters = $request['parameters'];
        if (isset($request['children'])) {
            $ids = [];
            foreach ($request['children'] as $child) {
                $id = $this->create($child, $token);
                if (isset($child['video_url'])) {
                    $this->wait($id, $token, 'video');
                }
                $ids[] = $id;
            }
            $parameters['children'] = implode(',', $ids);
        }

        $containerId = $this->create($parameters, $token);
        $this->wait($containerId, $token, match ($parameters['media_type'] ?? null) {
            'REELS' => 'video',
            'CAROUSEL' => 'carousel',
            default => 'image',
        });
        $published = $this->metaGraphClient->request('POST', '/' . $this->instagramId() . '/media_publish', ['creation_id' => $containerId, ...$token]);

        return (string) $published['id'];
    }

    public function preview(string $text, SocialContent $content, array $medias = []): array
    {
        return ['path' => '/' . $this->instagramId() . '/media', ...$this->request($text, $content, $medias)];
    }

    // The container publish() creates, token aside, with the children a carousel creates before it
    /**
     * @param list<PostMedia> $medias
     *
     * @return array{parameters: array<string, string>, children?: list<array<string, string>>}
     */
    private function request(string $text, SocialContent $content, array $medias): array
    {
        // No media of its own: the content's image
        if ([] === $medias) {
            $imageUrl = $this->imageExporter->jpegUrl($content, self::MIN_RATIO, self::MAX_RATIO)
                ?? throw new \RuntimeException('Instagram only takes posts with an image, and this content has none.');

            return ['parameters' => ['image_url' => $imageUrl, 'caption' => $text, 'alt_text' => mb_substr($content->imageAlt ?? $content->title, 0, 1000)]];
        }

        // One media: a picture, or a video as a reel
        if (1 === \count($medias)) {
            $media = $medias[0];

            return ['parameters' => $media->isVideo()
                ? ['media_type' => 'REELS', 'video_url' => $media->url, 'caption' => $text]
                : ['image_url' => $this->imageUrl($media, $content), 'caption' => $text, 'alt_text' => mb_substr($media->alt ?? $content->imageAlt ?? $content->title, 0, 1000)]];
        }

        // Several: a carousel of child containers
        $children = array_map(fn (PostMedia $media): array => $media->isVideo()
            ? ['is_carousel_item' => 'true', 'media_type' => 'VIDEO', 'video_url' => $media->url]
            : ['is_carousel_item' => 'true', 'image_url' => $this->imageUrl($media, $content)], $medias);

        return ['parameters' => ['media_type' => 'CAROUSEL', 'caption' => $text], 'children' => $children];
    }

    // The url of an uploaded picture framed within Instagram's ratios, as the content's own image is
    private function imageUrl(PostMedia $media, SocialContent $content): string
    {
        return $this->imageExporter->jpegUrl(new SocialContent($content->sourceId, $content->title, $content->url, imagePath: $media->path), self::MIN_RATIO, self::MAX_RATIO)
            ?? throw new \RuntimeException('This picture could not be served to Instagram: is "site-url" set?');
    }

    // Creates one container and returns its id
    /**
     * @param array<string, string> $parameters
     * @param array<string, string> $token
     */
    private function create(array $parameters, array $token): string
    {
        return (string) $this->metaGraphClient->request('POST', '/' . $this->instagramId() . '/media', [...$parameters, ...$token])['id'];
    }

    // Asks Instagram until it has processed the container, throwing when it failed or is still at it
    /** @param array<string, string> $token */
    private function wait(string $containerId, array $token, string $what): void
    {
        $attempts = 'video' === $what ? self::VIDEO_STATUS_ATTEMPTS : self::STATUS_ATTEMPTS;
        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            $status = (string) ($this->metaGraphClient->request('GET', '/' . $containerId, ['fields' => 'status_code', ...$token])['status_code'] ?? '');
            if ('FINISHED' === $status) {
                return;
            }
            if ('IN_PROGRESS' !== $status) {
                throw new \RuntimeException(sprintf('Instagram could not process the %s (%s).', $what, $status));
            }
            sleep($this->statusDelay);
        }

        throw new \RuntimeException(sprintf('Instagram is still processing the %s: publish it again in a few minutes.', $what));
    }

    private function instagramId(): string
    {
        return trim((string) $this->configService->get('social-meta-instagram-id'));
    }
}
