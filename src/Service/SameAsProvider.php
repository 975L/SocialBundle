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
use c975L\UiBundle\Contract\SameAsProviderInterface;
use c975L\UiBundle\Repository\BlockRepository;

// Hands UiBundle's "contact_details" graph the profiles this bundle already owns - the site-wide social links, and the Google listing the reviews are imported from. Each is a page naming the same business, which is what "sameAs" publishes; neither has any business being retyped into the contact block
class SameAsProvider implements SameAsProviderInterface
{
    private const string KIND = 'social_links';

    public function __construct(
        private readonly BlockRepository $blockRepository,
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    public function getSameAs(): array
    {
        return [...$this->listingUrl(), ...$this->socialLinks()];
    }

    // The listing first: it is the profile Google itself reconciles the site against, where a social account only corroborates it
    /**
     * @return string[]
     */
    private function listingUrl(): array
    {
        $url = $this->configService->get('social-google-listing-url');

        return is_string($url) && '' !== trim($url) ? [trim($url)] : [];
    }

    // Read from the singleton block rather than cached: the graph is built inside a "contact_details" render, itself already cached under the block's own entry
    /**
     * @return string[]
     */
    private function socialLinks(): array
    {
        $block = $this->blockRepository->findOneByKind(self::KIND);
        $urls = [];

        foreach ($block?->getData()['links'] ?? [] as $link) {
            $url = is_array($link) ? trim((string) ($link['url'] ?? '')) : '';

            // Http(s) only: the field takes any scheme, and a "mailto:" or "tel:" entry is a way to reach the business, not a page naming it - which is all "sameAs" is read as
            if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
                $urls[] = $url;
            }
        }

        return $urls;
    }
}
