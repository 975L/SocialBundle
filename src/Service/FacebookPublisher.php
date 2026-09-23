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

// Posts on the site's Facebook Page, never on a personal profile: a photo with its text when the content has an image, a link otherwise - Facebook then drawing the page's own preview
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

    // Review unless the site said otherwise, as on every network
    public function isAutomatic(): bool
    {
        return 'auto' === $this->configService->get('social-facebook-publish-mode');
    }

    public function getMaxLength(): int
    {
        return self::MAX_LENGTH;
    }

    // The post's own id ("pageid_postid"), its public address being facebook.com/ followed by it
    public function publish(string $text, SocialContent $content): string
    {
        ['path' => $path, 'parameters' => $parameters] = $this->request($text, $content);
        $posted = $this->metaGraphClient->request('POST', $path, [...$parameters, 'access_token' => (string) $this->metaGraphClient->pageToken()]);

        return (string) ($posted['post_id'] ?? $posted['id']);
    }

    public function preview(string $text, SocialContent $content): array
    {
        return $this->request($text, $content);
    }

    // The call publish() makes, token aside. "caption" is the text of a photo, "message" the text of a post
    /** @return array{path: string, parameters: array<string, string>} */
    private function request(string $text, SocialContent $content): array
    {
        $imageUrl = $this->imageExporter->jpegUrl($content);

        return null === $imageUrl
            ? ['path' => '/' . $this->pageId() . '/feed', 'parameters' => ['message' => $text, 'link' => $content->url]]
            : ['path' => '/' . $this->pageId() . '/photos', 'parameters' => ['url' => $imageUrl, 'caption' => $text]];
    }

    private function pageId(): string
    {
        return trim((string) $this->configService->get('social-meta-page-id'));
    }
}
