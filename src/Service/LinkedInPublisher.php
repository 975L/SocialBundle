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

// Posts on the connected member's own LinkedIn profile, as an article card - the link, its title and the content's image, which LinkedIn does not fetch itself from an API post and is uploaded first - or with the post's own pictures or video. A Company Page is out of reach of a self-serve app (see LinkedInClient)
class LinkedInPublisher implements NetworkPublisherInterface
{
    // What a post's commentary accepts
    private const int MAX_LENGTH = 3000;

    public function __construct(
        private readonly LinkedInClient $linkedInClient,
        private readonly SocialImageExporter $imageExporter,
    ) {
    }

    public function getName(): string
    {
        return 'linkedin';
    }

    // Connected with a token still valid: an expired one would only fail every post until the member connects again
    public function isConfigured(): bool
    {
        return $this->linkedInClient->isConnected();
    }

    public function getMaxLength(): int
    {
        return self::MAX_LENGTH;
    }

    // From learn.microsoft.com/linkedin/marketing/community-management/shares: Images API (JPG, GIF, PNG under 36,152,320 pixels), MultiImage API (2 to 20 pictures), Videos API (MP4, 3 s to 30 min, 75 KB to 500 MB)
    public function getMediaRules(): MediaRules
    {
        return new MediaRules(
            maxImages: 20,
            video: true,
            mix: false,
            required: false,
            imageTypes: ['image/jpeg', 'image/png', 'image/gif'],
            videoTypes: ['video/mp4'],
            maxVideoBytes: 500000000,
            minDuration: 3.0,
            maxDuration: 1800.0,
        );
    }

    // The post's urn, its public address being linkedin.com/feed/update/ followed by it
    public function publish(string $text, SocialContent $content, array $medias = []): string
    {
        if ([] !== $medias) {
            return $this->linkedInClient->createPost($this->post($text, $content, array_map($this->upload(...), $medias)));
        }

        $bytes = $this->imageExporter->bytes($content);
        // A card without its picture is still the post: an image LinkedIn refuses never keeps the text from going out
        $image = null;
        if (null !== $bytes) {
            try {
                $image = $this->linkedInClient->uploadImage($bytes);
            } catch (\RuntimeException) {
                $image = null;
            }
        }

        return $this->linkedInClient->createPost($this->post($text, $content, null === $image ? [] : [['id' => $image]]));
    }

    public function preview(string $text, SocialContent $content, array $medias = []): array
    {
        if ([] !== $medias) {
            return $this->post($text, $content, array_map(static fn (PostMedia $media): array => self::media($media, $media->isVideo() ? 'urn:li:video:(uploaded first)' : 'urn:li:image:(uploaded first)'), $medias));
        }

        return $this->post($text, $content, null === $content->imagePath && null === $content->imageUrl ? [] : [['id' => 'urn:li:image:(uploaded first)']]);
    }

    // Uploads one of the post's own medias - one LinkedIn refuses fails the post, being what its author chose to show
    /** @return array{id: string, altText?: string} */
    private function upload(PostMedia $media): array
    {
        if ($media->isVideo()) {
            return self::media($media, $this->linkedInClient->uploadVideo($media->path));
        }

        $bytes = @file_get_contents($media->path);
        if (false === $bytes) {
            throw new \RuntimeException(sprintf('The picture "%s" cannot be read.', $media->path));
        }

        return self::media($media, $this->linkedInClient->uploadImage($bytes));
    }

    // A media as the post refers to it, its alternative text when it has one
    /** @return array{id: string, altText?: string} */
    private static function media(PostMedia $media, string $urn): array
    {
        return null === $media->alt || '' === $media->alt ? ['id' => $urn] : ['id' => $urn, 'altText' => $media->alt];
    }

    // The body of the Posts API: public, in the main feed, the link as an article card with the one picture as thumbnail - several pictures go as a multi-image post and a video as itself, the link staying in the text; a post written with no link shows its media alone, or its text alone
    /**
     * @param list<array{id: string, altText?: string}> $medias
     *
     * @return array<string, mixed>
     */
    private function post(string $text, SocialContent $content, array $medias): array
    {
        $body = [
            'author' => $this->linkedInClient->author(),
            'commentary' => $this->commentary($text),
            'visibility' => 'PUBLIC',
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];

        $first = $medias[0]['id'] ?? null;
        if (\count($medias) > 1) {
            $body['content'] = ['multiImage' => ['images' => $medias]];
        } elseif ('' !== $content->url && (null === $first || !str_starts_with($first, 'urn:li:video:'))) {
            $body['content'] = ['article' => array_filter(['source' => $content->url, 'title' => mb_substr($content->title, 0, 400), 'thumbnail' => $first])];
        } elseif (null !== $first) {
            $body['content'] = ['media' => $medias[0]];
        }

        return $body;
    }

    // The commentary is "little text" for LinkedIn: these characters are its markup, and posted raw they cut the text or vanish. A hashtag is the one kept as markup, written the way LinkedIn links it - matched first, so the underscore inside one is escaped within it rather than ending it
    private function commentary(string $text): string
    {
        return (string) preg_replace_callback(
            '/#([\p{L}\p{N}_]+)|([\\\\|{}@\[\]()<>#*_~])/u',
            static fn (array $match): string => '' !== $match[1] ? '{hashtag|\#|' . str_replace('_', '\_', $match[1]) . '}' : '\\' . $match[2],
            $text,
        );
    }
}
