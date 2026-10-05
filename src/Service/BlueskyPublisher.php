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

// Posts on Bluesky through the AT Protocol, with the OAuth connection made on the "Connexions" screen (see BlueskyOAuthClient)
class BlueskyPublisher implements NetworkPublisherInterface
{
    // What app.bsky.feed.post accepts, counted in graphemes - as many code points never exceed it
    private const int MAX_LENGTH = 300;

    // What app.bsky.embed.images accepts per image, an image above it being posted without it rather than failing the post
    private const int MAX_IMAGE_SIZE = 2000000;

    public function __construct(
        private readonly SocialImageExporter $imageExporter,
        private readonly BlueskyOAuthClient $oauthClient,
    ) {
    }

    public function getName(): string
    {
        return 'bluesky';
    }

    public function isConfigured(): bool
    {
        return $this->oauthClient->isConnected();
    }

    public function getMaxLength(): int
    {
        return self::MAX_LENGTH;
    }

    // Sources: atproto lexicons app.bsky.embed.images (maxLength 4, blob maxSize 2,000,000) and app.bsky.embed.video (video/mp4, maxSize 300,000,000), 600 s being the official client's limit (social-app constants.ts)
    public function getMediaRules(): MediaRules
    {
        return new MediaRules(
            maxImages: 4,
            video: true,
            mix: false,
            required: false,
            imageTypes: ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            maxImageBytes: self::MAX_IMAGE_SIZE,
            videoTypes: ['video/mp4'],
            maxVideoBytes: 300000000,
            maxDuration: 600.0,
        );
    }

    // Uploads the post's blobs, then writes the record in the connected repo
    public function publish(string $text, SocialContent $content, array $medias = []): string
    {
        $created = $this->oauthClient->call('com.atproto.repo.createRecord', ['json' => [
            'repo' => (string) $this->oauthClient->getDid(),
            'collection' => 'app.bsky.feed.post',
            'record' => $this->record($text, $content, $medias, true),
        ]]);

        return (string) $created['uri'];
    }

    // The record as createRecord would receive it, the blobs Bluesky has not issued yet standing as their own path or url
    public function preview(string $text, SocialContent $content, array $medias = []): array
    {
        return $this->record($text, $content, $medias, false);
    }

    // The post's text, its facets and its embed, the blobs uploaded only when $upload
    /**
     * @param list<PostMedia> $medias
     *
     * @return array<string, mixed>
     */
    private function record(string $text, SocialContent $content, array $medias, bool $upload): array
    {
        if (mb_strlen($text) > self::MAX_LENGTH) {
            $text = rtrim(mb_substr($text, 0, self::MAX_LENGTH - 1)) . '…';
        }

        $record = [
            '$type' => 'app.bsky.feed.post',
            'text' => $text,
            'createdAt' => new \DateTimeImmutable()->format(\DATE_ATOM),
            'facets' => $this->facets($text),
        ];

        $embed = $this->embed($content, $medias, $upload);
        if (null !== $embed) {
            $record['embed'] = $embed;
        }

        return $record;
    }

    // The post's medias in their order, the content's own image when it has none - null when there is nothing to embed
    /**
     * @param list<PostMedia> $medias
     *
     * @return array<string, mixed>|null
     */
    private function embed(SocialContent $content, array $medias, bool $upload): ?array
    {
        // A video wins over pictures, the rules forbidding both in one post
        foreach ($medias as $media) {
            if ($media->isVideo()) {
                return array_filter([
                    '$type' => 'app.bsky.embed.video',
                    'video' => $this->blob($upload, $media->mimeType, $media->url, $media->path),
                    'alt' => $media->alt,
                    'aspectRatio' => $this->aspectRatio($media->width, $media->height),
                ], static fn (mixed $value): bool => null !== $value);
            }
        }

        $images = [];
        foreach (\array_slice($medias, 0, 4) as $media) {
            $images[] = $this->imageEntry($this->blob($upload, $media->mimeType, $media->url, $media->path), $media->alt ?? '', $media->width, $media->height);
        }

        // No media of its own: the content's image, posted without it when Bluesky would refuse it
        if ([] === $medias) {
            $image = $this->image($content);
            if (null === $image) {
                return null;
            }
            $standIn = (string) ($content->imagePath ?? $content->imageUrl);
            $images[] = $this->imageEntry($this->blob($upload, $image['mime'], $standIn, $standIn, $image['bytes']), $content->imageAlt ?? $content->title, $image['width'], $image['height']);
        }

        return ['$type' => 'app.bsky.embed.images', 'images' => $images];
    }

    // The blob uploadBlob issues for these bytes - read from the file when not given - or, for the dry run, the stand-in it would replace
    private function blob(bool $upload, string $mime, string $standIn, string $path, ?string $bytes = null): mixed
    {
        if (!$upload) {
            return $standIn;
        }

        $bytes ??= @file_get_contents($path);
        if (false === $bytes) {
            throw new \RuntimeException(\sprintf('Bluesky media unreadable: %s', $path));
        }

        return $this->oauthClient->call('com.atproto.repo.uploadBlob', [
            'headers' => ['Content-Type' => $mime],
            'body' => $bytes,
        ])['blob'];
    }

    // One entry of app.bsky.embed.images
    /** @return array<string, mixed> */
    private function imageEntry(mixed $blob, string $alt, ?int $width, ?int $height): array
    {
        return array_filter([
            'image' => $blob,
            'alt' => $alt,
            'aspectRatio' => $this->aspectRatio($width, $height),
        ], static fn (mixed $value): bool => null !== $value);
    }

    // Without it, the apps crop the media to a default ratio until they have loaded it - null while either side is unknown
    /** @return array{width: int, height: int}|null */
    private function aspectRatio(?int $width, ?int $height): ?array
    {
        return null === $width || null === $height || $width < 1 || $height < 1 ? null : ['width' => $width, 'height' => $height];
    }

    // Links and hashtags made clickable: Bluesky shows a post's text as it is, the facets saying which bytes link where. preg_match_all's offsets being bytes too, they are the UTF-8 offsets the protocol wants, an accented title before the url included
    /** @return list<array<string, mixed>> */
    private function facets(string $text): array
    {
        $facets = [];

        preg_match_all('~https?://[^\s]+[^\s.,;:!?)]~u', $text, $links, \PREG_OFFSET_CAPTURE);
        foreach ($links[0] as [$url, $offset]) {
            $facets[] = $this->facet($offset, $url, ['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]);
        }

        preg_match_all('~(?<=^|\s)#([\p{L}\p{N}_]+)~u', $text, $tags, \PREG_OFFSET_CAPTURE);
        foreach ($tags[0] as $index => [$tag, $offset]) {
            $facets[] = $this->facet($offset, $tag, ['$type' => 'app.bsky.richtext.facet#tag', 'tag' => $tags[1][$index][0]]);
        }

        return $facets;
    }

    /**
     * @param array<string, string> $feature
     *
     * @return array<string, mixed>
     */
    private function facet(int $offset, string $match, array $feature): array
    {
        return [
            'index' => ['byteStart' => $offset, 'byteEnd' => $offset + \strlen($match)],
            'features' => [$feature],
        ];
    }

    // The image's bytes and what the post says of them - null when there is none, or one Bluesky would refuse
    /** @return array{mime: string, bytes: string, width: int, height: int}|null */
    private function image(SocialContent $content): ?array
    {
        $bytes = $this->imageExporter->bytes($content);
        $size = null === $bytes || \strlen($bytes) > self::MAX_IMAGE_SIZE ? false : getimagesizefromstring($bytes);
        if (false === $size) {
            return null;
        }

        return ['mime' => $size['mime'], 'bytes' => (string) $bytes, 'width' => $size[0], 'height' => $size[1]];
    }
}
