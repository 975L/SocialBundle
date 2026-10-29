<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Twig;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Service\GoogleBusinessProfileSource;
use c975L\UiBundle\Repository\ReviewRepository;
use c975L\UiBundle\Service\ReviewCollectionSourceProvider;
use Twig\Attribute\AsTwigFunction;

// What the "google_reviews" block draws (see blocks/GoogleReviews.html.twig): the imported Google reviews the moderation list leaves displayed, whatever "ui-enable-reviews" says - that switch governs what visitors write here, not what a platform already published
class GoogleReviewsExtension
{
    public function __construct(
        private readonly ReviewRepository $reviewRepository,
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    // Each card carries the variables ReviewItem.html.twig reads, mapped as the "collection" block maps them (see UiBundle's CollectionRuntime), so the two draws of a review never drift apart. The average covers every displayed review, never the "limit" first ones: a figure over a subset would turn the subset into a selection
    /**
     * @return array{items: array<int, array<string, mixed>>, count: int, average: float|null, listingUrl: string|null}
     */
    #[AsTwigFunction('social_google_reviews')]
    public function getGoogleReviews(?int $limit = null): array
    {
        $items = [];
        foreach ($this->reviewRepository->findForDisplay(GoogleBusinessProfileSource::NAME, null !== $limit && $limit > 0 ? $limit : null) as $review) {
            $item = ReviewCollectionSourceProvider::buildItem($review);
            $items[] = [
                ...$item->data,
                'title' => $item->title,
                'content' => $item->description,
                'url' => $item->url,
                'imageUrl' => $item->imageUrl,
            ];
        }

        // Asked only when something is drawn: an empty block renders nothing, the aggregate included
        $aggregate = [] === $items ? ['count' => 0, 'average' => null] : $this->reviewRepository->getAggregate(GoogleBusinessProfileSource::NAME);
        $listingUrl = $this->configService->get(GoogleBusinessProfileSource::LISTING_URL);

        return [
            'items' => $items,
            'count' => $aggregate['count'],
            'average' => $aggregate['average'],
            'listingUrl' => is_string($listingUrl) && '' !== trim($listingUrl) ? trim($listingUrl) : null,
        ];
    }
}
