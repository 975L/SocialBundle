<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\SocialBundle\Contract\NetworkPublisherInterface;
use c975L\SocialBundle\Model\MediaRules;
use c975L\SocialBundle\Model\PostMedia;
use c975L\UiBundle\Model\SocialContent;

// Pins the post on the board picked (see PinterestClient): its video, uploaded, with the content's picture as cover (the site's default one failing that), or one picture - the post's own, the content's otherwise - its title the text's first line, its description the lines after, and the content's address as the pin's link, which is what a pin is for. Pictures are served to Pinterest as a JPEG copy, the format it fetches every time
class PinterestPublisher implements NetworkPublisherInterface
{
    // What a pin's description accepts
    private const int MAX_LENGTH = 800;

    // What a pin's title accepts
    private const int MAX_TITLE = 100;

    // What a pin's alternative text accepts
    private const int MAX_ALT = 500;

    public function __construct(
        private readonly PinterestClient $pinterestClient,
        private readonly SocialImageExporter $imageExporter,
        private readonly string $projectDir,
    ) {
    }

    public function getName(): string
    {
        return 'pinterest';
    }

    // Connected, and a board picked to pin on
    public function isConfigured(): bool
    {
        return $this->pinterestClient->isConnected() && $this->pinterestClient->hasBoard();
    }

    public function getMaxLength(): int
    {
        return self::MAX_LENGTH;
    }

    // From developers.pinterest.com/docs/api/v5/pins-create and its help on video Pins: one picture, JPEG or PNG, or one video of 4 s to 15 min under 2 GB - a pin without either does not exist
    public function getMediaRules(): MediaRules
    {
        return new MediaRules(
            maxImages: 1,
            video: true,
            required: true,
            imageTypes: ['image/jpeg', 'image/png'],
            videoTypes: ['video/mp4', 'video/quicktime'],
            maxVideoBytes: 2 * 1024 * 1024 * 1024,
            minDuration: 4.0,
            maxDuration: 900.0,
        );
    }

    // The pin's id, its public address being pinterest.com/pin/ followed by it
    public function publish(string $text, SocialContent $content, array $medias = []): string
    {
        $video = $this->video($medias);
        if (null !== $video) {
            // The cover checked before the upload, which may take minutes
            $pin = $this->pin($text, $this->covered($content), []);
            $pin['media_source'] = (array) $pin['media_source'] + ['media_id' => $this->pinterestClient->uploadVideo($video->path)];

            return $this->pinterestClient->createPin($pin);
        }

        return $this->pinterestClient->createPin($this->pin($text, $this->pictured($content, $medias)));
    }

    // The source picture in place of its JPEG copy and the video's address in place of its upload, a preview writing and sending nothing
    public function preview(string $text, SocialContent $content, array $medias = []): array
    {
        $video = $this->video($medias);

        return null === $video ? $this->pin($text, $this->pictured($content, $medias), null, true) : $this->pin($text, $this->covered($content), ['url' => $video->url], true);
    }

    // The post's first video, null for a pin of a picture
    /** @param list<PostMedia> $medias */
    private function video(array $medias): ?PostMedia
    {
        return array_find($medias, static fn (PostMedia $media): bool => $media->isVideo());
    }

    // The content with the cover of a video: its own picture, the site's default "og-image" when it has none
    private function covered(SocialContent $content): SocialContent
    {
        if (null !== $content->imagePath || null !== $content->imageUrl) {
            return $content;
        }
        $default = glob($this->projectDir . '/public/og-image.*') ?: [];

        return [] === $default ? $content : new SocialContent($content->sourceId, $content->title, $content->url, imagePath: $default[0]);
    }

    // The content with the picture to pin: the post's own first, with its alternative text, the content's otherwise
    /** @param list<PostMedia> $medias */
    private function pictured(SocialContent $content, array $medias): SocialContent
    {
        $media = $medias[0] ?? null;

        return null === $media ? $content : new SocialContent($content->sourceId, $content->title, $content->url, imagePath: $media->path, imageAlt: $media->alt ?? $content->imageAlt);
    }

    // The body of POST /v5/pins, the picture served as a JPEG, as the pin or as its video's cover: the title is the text's first line, the content's title when the text is empty, the description the lines after - the whole text for a single line too long a title, none otherwise
    /**
     * @param array<string, string>|null $video
     *
     * @return array<string, mixed>
     */
    private function pin(string $text, SocialContent $content, ?array $video = null, bool $preview = false): array
    {
        $source = $content->imagePath ?? $content->imageUrl
            ?? throw new \RuntimeException('Pinterest only takes pins with an image, and this content has none.');
        $imageUrl = $preview ? $source : ($this->imageExporter->jpegUrl($content) ?? throw new \RuntimeException('This picture could not be served to Pinterest: is "site-url" set?'));
        [$first, $rest] = array_pad(explode("\n", trim($text), 2), 2, '');
        $title = '' !== trim($first) ? trim($first) : $content->title;
        $description = '' !== trim($rest) ? trim($rest) : (mb_strlen($title) > self::MAX_TITLE ? trim($text) : null);

        return array_filter([
            'title' => mb_substr($title, 0, self::MAX_TITLE),
            'description' => null === $description ? null : mb_substr($description, 0, self::MAX_LENGTH),
            'link' => '' === $content->url ? null : $content->url,
            'alt_text' => null === $content->imageAlt || '' === $content->imageAlt ? null : mb_substr($content->imageAlt, 0, self::MAX_ALT),
            'media_source' => null === $video ? ['source_type' => 'image_url', 'url' => $imageUrl] : ['source_type' => 'video_id', 'cover_image_url' => $imageUrl] + $video,
        ], static fn (mixed $value): bool => null !== $value);
    }
}
