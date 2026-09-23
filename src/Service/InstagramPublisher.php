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

// Posts on the Instagram professional account linked to the site's Facebook Page, with the Page's own token. Instagram takes a picture or nothing, and the picture as a JPEG within its range of ratios (see SocialImageExporter). A link in a caption is not clickable on Instagram: a template meant for it says so ("link in bio") rather than relying on the url
class InstagramPublisher implements NetworkPublisherInterface
{
    // What a caption accepts
    private const int MAX_LENGTH = 2200;

    // The range of width/height ratios Instagram accepts, from 4:5 to 1.91:1
    private const float MIN_RATIO = 0.8;
    private const float MAX_RATIO = 1.91;

    // Instagram fetches and processes the image before it can be published: asked again this many times, this many seconds apart
    private const int STATUS_ATTEMPTS = 10;

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

    public function isAutomatic(): bool
    {
        return 'auto' === $this->configService->get('social-instagram-publish-mode');
    }

    public function getMaxLength(): int
    {
        return self::MAX_LENGTH;
    }

    // The media's id: the container is created, waited for until Instagram has processed its image, then published
    public function publish(string $text, SocialContent $content): string
    {
        $token = ['access_token' => (string) $this->metaGraphClient->pageToken()];
        $container = $this->metaGraphClient->request('POST', '/' . $this->instagramId() . '/media', [...$this->parameters($text, $content), ...$token]);
        $containerId = (string) $container['id'];

        for ($attempt = 1; $attempt <= self::STATUS_ATTEMPTS; ++$attempt) {
            $status = (string) ($this->metaGraphClient->request('GET', '/' . $containerId, ['fields' => 'status_code', ...$token])['status_code'] ?? '');
            if ('FINISHED' === $status) {
                $published = $this->metaGraphClient->request('POST', '/' . $this->instagramId() . '/media_publish', ['creation_id' => $containerId, ...$token]);

                return (string) $published['id'];
            }
            if ('IN_PROGRESS' !== $status) {
                throw new \RuntimeException(sprintf('Instagram could not process the image (%s).', $status));
            }
            sleep($this->statusDelay);
        }

        throw new \RuntimeException('Instagram is still processing the image: publish it again in a few minutes.');
    }

    public function preview(string $text, SocialContent $content): array
    {
        return ['path' => '/' . $this->instagramId() . '/media', 'parameters' => $this->parameters($text, $content)];
    }

    /**
     * @return array<string, string>
     */
    private function parameters(string $text, SocialContent $content): array
    {
        $imageUrl = $this->imageExporter->jpegUrl($content, self::MIN_RATIO, self::MAX_RATIO)
            ?? throw new \RuntimeException('Instagram only takes posts with an image, and this content has none.');

        return ['image_url' => $imageUrl, 'caption' => $text, 'alt_text' => mb_substr($content->imageAlt ?? $content->title, 0, 1000)];
    }

    private function instagramId(): string
    {
        return trim((string) $this->configService->get('social-meta-instagram-id'));
    }
}
