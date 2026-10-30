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

// Pins the post on the board picked (see PinterestClient): one picture - the post's own, the content's otherwise - its title the text's first line, its description the lines after, and the content's address as the pin's link, which is what a pin is for. Served to Pinterest as a JPEG copy, the format it fetches every time; no video yet, Pinterest taking one through an upload of its own
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

    // From developers.pinterest.com/docs/api/v5/pins-create: one picture per pin, JPEG or PNG; a pin without one does not exist
    public function getMediaRules(): MediaRules
    {
        return new MediaRules(
            maxImages: 1,
            video: false,
            required: true,
            imageTypes: ['image/jpeg', 'image/png'],
        );
    }

    // The pin's id, its public address being pinterest.com/pin/ followed by it
    public function publish(string $text, SocialContent $content, array $medias = []): string
    {
        return $this->pinterestClient->createPin($this->pin($text, $this->pictured($content, $medias)));
    }

    // The source picture in place of its JPEG copy, a preview writing nothing to disk
    public function preview(string $text, SocialContent $content, array $medias = []): array
    {
        return $this->pin($text, $this->pictured($content, $medias), true);
    }

    // The content with the picture to pin: the post's own first, with its alternative text, the content's otherwise
    /** @param list<PostMedia> $medias */
    private function pictured(SocialContent $content, array $medias): SocialContent
    {
        $media = $medias[0] ?? null;

        return null === $media ? $content : new SocialContent($content->sourceId, $content->title, $content->url, imagePath: $media->path, imageAlt: $media->alt ?? $content->imageAlt);
    }

    // The body of POST /v5/pins, the picture served as a JPEG: the title is the text's first line, the content's title when the text is empty, the description the lines after - the whole text for a single line too long a title, none otherwise
    /** @return array<string, mixed> */
    private function pin(string $text, SocialContent $content, bool $preview = false): array
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
            'media_source' => ['source_type' => 'image_url', 'url' => $imageUrl],
        ], static fn (mixed $value): bool => null !== $value);
    }
}
