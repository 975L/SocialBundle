<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\ConfigBundle\Service\SiteLocales;
use c975L\SocialBundle\Contract\NetworkPublisherInterface;
use c975L\SocialBundle\Contract\OnDemandPublisherInterface;
use c975L\SocialBundle\Model\MediaRules;
use c975L\SocialBundle\Model\PostMedia;
use c975L\UiBundle\Model\SocialContent;

// Posts on the Google listing the site was connected to (the reviews' connection, see GoogleBusinessClient): a "What's new" post, its text the summary, one picture - the post's own, the content's otherwise - served as a JPEG, and the content's address behind a "Learn more" button, a link written in the text not being one there. Ticked by hand only (see OnDemandPublisherInterface)
class GoogleBusinessPublisher implements NetworkPublisherInterface, OnDemandPublisherInterface
{
    public const string NAME = 'google';

    // What a post's summary accepts
    private const int MAX_LENGTH = 1500;

    public function __construct(
        private readonly GoogleBusinessClient $googleBusinessClient,
        private readonly SocialImageExporter $imageExporter,
        private readonly SiteLocales $siteLocales,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    // Connected, and a listing resolved
    public function isConfigured(): bool
    {
        return $this->googleBusinessClient->isConfigured();
    }

    public function getMaxLength(): int
    {
        return self::MAX_LENGTH;
    }

    // One picture or none, the v4 API taking photos only (MediaItem reference, which asks 250 px on the short edge and 10 KB at least); JPEG or PNG under 5 MB from Google's photo guidelines
    public function getMediaRules(): MediaRules
    {
        return new MediaRules(
            maxImages: 1,
            video: false,
            imageTypes: ['image/jpeg', 'image/png'],
            maxImageBytes: 5 * 1024 * 1024,
        );
    }

    // The post's resource name ("accounts/1/locations/2/localPosts/3"), Google giving a post no public address of its own
    public function publish(string $text, SocialContent $content, array $medias = []): string
    {
        $post = $this->googleBusinessClient->request('POST', $this->googleBusinessClient->locationPath() . '/localPosts', body: $this->post($text, $this->pictured($content, $medias)));
        // Accepted on its answer alone: a name missing from it never marks failed a post already on the listing
        $name = $post['name'] ?? null;

        return is_string($name) ? $name : '';
    }

    // The source picture in place of its JPEG copy, a preview writing and sending nothing
    public function preview(string $text, SocialContent $content, array $medias = []): array
    {
        return $this->post($text, $this->pictured($content, $medias), true);
    }

    // The content with the picture to post: the post's own first, the content's otherwise
    /** @param list<PostMedia> $medias */
    private function pictured(SocialContent $content, array $medias): SocialContent
    {
        $media = array_find($medias, static fn (PostMedia $media): bool => !$media->isVideo());

        return $media?->onContent($content) ?? $content;
    }

    // The body of POST .../localPosts: a standard post, in the content's language or the site's default one, Google filing a post without any as English, its picture and its button left out when it has none - the content's address taken out of the text, the template's own putting it there, since the button carries it
    /** @return array<string, mixed> */
    private function post(string $text, SocialContent $content, bool $preview = false): array
    {
        $source = $content->imagePath ?? $content->imageUrl;
        $imageUrl = null === $source || $preview ? $source : $this->imageExporter->jpegUrl($content);
        $summary = '' === $content->url ? $text : str_replace($content->url, '', $text);

        return array_filter([
            'languageCode' => $content->locale ?? $this->siteLocales->getDefaultLocale(),
            'summary' => mb_substr(trim($summary), 0, self::MAX_LENGTH),
            'topicType' => 'STANDARD',
            'callToAction' => '' === $content->url ? null : ['actionType' => 'LEARN_MORE', 'url' => $content->url],
            'media' => null === $imageUrl ? null : [['mediaFormat' => 'PHOTO', 'sourceUrl' => $imageUrl]],
        ], static fn (mixed $value): bool => null !== $value);
    }
}
