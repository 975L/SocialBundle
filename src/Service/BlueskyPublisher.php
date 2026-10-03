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
use c975L\UiBundle\Model\SocialContent;

// Posts on Bluesky through the AT Protocol, with the OAuth connection made on the "Connexions" screen (see BlueskyOAuthClient)
class BlueskyPublisher implements NetworkPublisherInterface
{
    // What app.bsky.feed.post accepts, counted in graphemes - as many code points never exceed it
    private const int MAX_LENGTH = 300;

    // What app.bsky.embed.images accepts per image, an image above it being posted without it rather than failing the post
    private const int MAX_IMAGE_SIZE = 2000000;

    public function __construct(
        private readonly ConfigServiceInterface $configService,
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

    // Review unless the site said otherwise: a post nobody read going out under the site's name is the one mistake not to make by default
    public function isAutomatic(): bool
    {
        return 'auto' === $this->configService->get('social-bluesky-publish-mode');
    }

    public function getMaxLength(): int
    {
        return self::MAX_LENGTH;
    }

    public function publish(string $text, SocialContent $content): string
    {
        $record = $this->record($text);
        $image = $this->image($content);
        if (null !== $image) {
            $uploaded = $this->oauthClient->call('com.atproto.repo.uploadBlob', [
                'headers' => ['Content-Type' => $image['mime']],
                'body' => $image['bytes'],
            ]);
            $record['embed'] = $this->embed($uploaded['blob'], $image, $content);
        }

        $created = $this->oauthClient->call('com.atproto.repo.createRecord', ['json' => [
            'repo' => (string) $this->oauthClient->getDid(),
            'collection' => 'app.bsky.feed.post',
            'record' => $record,
        ]]);

        return (string) $created['uri'];
    }

    // The record as createRecord would receive it, the blob Bluesky has not issued yet standing as the image's own path or url
    public function preview(string $text, SocialContent $content): array
    {
        $record = $this->record($text);
        $image = $this->image($content);
        if (null !== $image) {
            $record['embed'] = $this->embed($content->imagePath ?? $content->imageUrl, $image, $content);
        }

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function record(string $text): array
    {
        if (mb_strlen($text) > self::MAX_LENGTH) {
            $text = rtrim(mb_substr($text, 0, self::MAX_LENGTH - 1)) . '…';
        }

        return [
            '$type' => 'app.bsky.feed.post',
            'text' => $text,
            'createdAt' => new \DateTimeImmutable()->format(\DATE_ATOM),
            'facets' => $this->facets($text),
        ];
    }

    /**
     * @param array{mime: string, bytes: string, width: int, height: int} $image
     *
     * @return array<string, mixed>
     */
    private function embed(mixed $blob, array $image, SocialContent $content): array
    {
        return ['$type' => 'app.bsky.embed.images', 'images' => [[
            'image' => $blob,
            'alt' => $content->imageAlt ?? $content->title,
            // Without it, the apps crop the image to a default ratio until they have loaded it
            'aspectRatio' => ['width' => $image['width'], 'height' => $image['height']],
        ]]];
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
