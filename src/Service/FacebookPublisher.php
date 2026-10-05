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

    // The post's own id ("pageid_postid", a video's id for a video), its public address being facebook.com/ followed by it. Several pictures are first uploaded unpublished, then attached to one post
    public function publish(string $text, SocialContent $content, array $medias = []): string
    {
        $token = ['access_token' => (string) $this->metaGraphClient->pageToken()];
        $request = $this->request($text, $content, $medias);

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

    // The calls publish() makes, token aside. "caption" is the text of a photo, "description" that of a video, "message" that of a post, its "link" left out for a post written with none
    /**
     * @param list<PostMedia> $medias
     *
     * @return array{path: string, parameters: array<string, string>, uploads?: list<array{path: string, parameters: array<string, string>}>}
     */
    private function request(string $text, SocialContent $content, array $medias): array
    {
        $page = '/' . $this->pageId();

        // The post's video, alone as Facebook takes it
        foreach ($medias as $media) {
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
