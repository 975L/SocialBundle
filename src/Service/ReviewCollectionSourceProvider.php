<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\SocialBundle\Entity\Review;
use c975L\SocialBundle\Repository\ReviewRepository;
use c975L\UiBundle\Contract\CollectionSourceProviderInterface;
use c975L\UiBundle\Model\CollectionItem;

// Exposes the imported reviews to UiBundle's generic "collection" block, so this bundle ships no block kind of its own for them - an editor picks "Avis" as the source of a collection already on the page
class ReviewCollectionSourceProvider implements CollectionSourceProviderInterface
{
    public const string CACHE_TAG = 'social_reviews';

    private const string ITEM_TEMPLATE = '@c975LSocial/collection/ReviewItem.html.twig';

    public function __construct(private readonly ReviewRepository $reviewRepository)
    {
    }

    public function getSources(): array
    {
        return [
            'social.collection.reviews' => [
                // Rendered by UiBundle's CollectionType, whose domain is "ui" - hence the key living in this bundle's own translations/ui.*.xlf rather than in social.*.xlf
                'label' => 'label.reviews_collection_source',
                'count' => fn (): int => $this->reviewRepository->getAggregate()['count'],
                'items' => $this->buildItems(...),
                'cacheTags' => [self::CACHE_TAG],
                'itemTemplate' => self::ITEM_TEMPLATE,
            ],
        ];
    }

    // An array rather than a generator: CollectionSourceRegistry promises one, and UiBundle shuffles it and reads its first and last keys
    /**
     * @return CollectionItem[]
     */
    private function buildItems(?int $limit): array
    {
        return array_map($this->buildItem(...), $this->reviewRepository->findForDisplay(null, $limit));
    }

    // The rating, the date and the reply travel in "data": the built-in card knows none of them, and ReviewItem.html.twig - which this source names as its own template - reads them from there
    private function buildItem(Review $review): CollectionItem
    {
        return new CollectionItem(
            // Empty rather than null, CollectionItem taking a plain string - ReviewItem.html.twig prints its own "anonymous" label in its place
            title: $review->getAuthorName() ?? '',
            description: $review->getComment(),
            imageUrl: $review->getAuthorAvatarUrl(),
            url: $review->getSourceUrl(),
            data: [
                'id' => $review->getId(),
                'rating' => $review->getRating(),
                'publishedAt' => $review->getPublishedAt(),
                'replyComment' => $review->getReplyComment(),
                'repliedAt' => $review->getRepliedAt(),
                'source' => $review->getSource(),
                'verified' => $review->isVerified(),
            ],
        );
    }
}
