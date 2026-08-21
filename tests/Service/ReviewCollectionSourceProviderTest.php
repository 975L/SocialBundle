<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Entity\Review;
use c975L\SocialBundle\Repository\ReviewRepository;
use c975L\SocialBundle\Service\ReviewCollectionSourceProvider;
use c975L\UiBundle\Model\CollectionItem;
use PHPUnit\Framework\TestCase;

class ReviewCollectionSourceProviderTest extends TestCase
{
    private function createReview(): Review
    {
        return new Review()
            ->setSource('google')
            ->setExternalId('a')
            ->setAuthorName('Jean D.')
            ->setAuthorAvatarUrl('https://example.org/avatar.png')
            ->setRating(4)
            ->setComment('Impeccable')
            ->setPublishedAt(new \DateTimeImmutable('2026-08-01'))
            ->setSourceUrl('https://maps.google.com/review/a')
        ;
    }

    private function createProvider(Review ...$reviews): ReviewCollectionSourceProvider
    {
        $repository = $this->createStub(ReviewRepository::class);
        $repository->method('findForDisplay')->willReturn($reviews);
        $repository->method('getAggregate')->willReturn(['count' => count($reviews), 'average' => 4.0]);

        return new ReviewCollectionSourceProvider($repository);
    }

    // The key and the cache tag are read by CollectionSourceRegistry and by ReviewCacheInvalidationListener, so both are part of the contract
    public function testGetSourcesDeclaresTheReviewsSourceWithItsCacheTagAndTemplate(): void
    {
        $source = $this->createProvider()->getSources()['social.collection.reviews'];

        $this->assertSame('label.reviews_collection_source', $source['label']);
        $this->assertSame([ReviewCollectionSourceProvider::CACHE_TAG], $source['cacheTags']);
        $this->assertSame('@c975LSocial/collection/ReviewItem.html.twig', $source['itemTemplate']);
    }

    // The rating, the date and the reply have no argument of their own on CollectionItem, so they travel in "data" for the item template to read
    public function testItemsCarryTheRatingAndTheDateInData(): void
    {
        $source = $this->createProvider($this->createReview())->getSources()['social.collection.reviews'];

        $items = $source['items'](null);
        $item = $items[0];

        $this->assertInstanceOf(CollectionItem::class, $item);
        $this->assertSame('Jean D.', $item->title);
        $this->assertSame('Impeccable', $item->description);
        $this->assertSame('https://example.org/avatar.png', $item->imageUrl);
        $this->assertSame('https://maps.google.com/review/a', $item->url);
        $this->assertSame(4, $item->data['rating']);
        $this->assertSame('google', $item->data['source']);
    }

    public function testCountReportsTheAggregateTotal(): void
    {
        $source = $this->createProvider($this->createReview(), $this->createReview())->getSources()['social.collection.reviews'];

        $this->assertSame(2, $source['count']());
    }
}
