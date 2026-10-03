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

// Posts on the connected member's own LinkedIn profile, as an article card - the link, its title and the content's image, which LinkedIn does not fetch itself from an API post and is uploaded first. A Company Page is out of reach of a self-serve app (see LinkedInClient)
class LinkedInPublisher implements NetworkPublisherInterface
{
    // What a post's commentary accepts
    private const int MAX_LENGTH = 3000;

    public function __construct(
        private readonly LinkedInClient $linkedInClient,
        private readonly SocialImageExporter $imageExporter,
        private readonly ConfigServiceInterface $configService,
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

    // Review unless the site said otherwise, as on every network
    public function isAutomatic(): bool
    {
        return 'auto' === $this->configService->get('social-linkedin-publish-mode');
    }

    public function getMaxLength(): int
    {
        return self::MAX_LENGTH;
    }

    // The post's urn, its public address being linkedin.com/feed/update/ followed by it
    public function publish(string $text, SocialContent $content): string
    {
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

        return $this->linkedInClient->createPost($this->post($text, $content, $image));
    }

    public function preview(string $text, SocialContent $content): array
    {
        return $this->post($text, $content, null === $content->imagePath && null === $content->imageUrl ? null : 'urn:li:image:(uploaded first)');
    }

    // The body of the Posts API: public, in the main feed, the link as an article card
    /** @return array<string, mixed> */
    private function post(string $text, SocialContent $content, ?string $image): array
    {
        $article = ['source' => $content->url, 'title' => mb_substr($content->title, 0, 400)];
        if (null !== $image) {
            $article['thumbnail'] = $image;
        }

        return [
            'author' => $this->linkedInClient->author(),
            'commentary' => $this->commentary($text),
            'visibility' => 'PUBLIC',
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'content' => ['article' => $article],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];
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
