<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
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
        return $this->createProviderWith(true, ...$reviews);
    }

    // The feature switch is a parameter of its own: every test but one runs with the reviews turned on, which is the only state where the source exists at all
    private function createProviderWith(bool $reviewsEnabled, Review ...$reviews): ReviewCollectionSourceProvider
    {
        $repository = $this->createStub(ReviewRepository::class);
        $repository->method('findForDisplay')->willReturn($reviews);
        $repository->method('getAggregate')->willReturn(['count' => count($reviews), 'average' => 4.0]);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $slug): string => match ($slug) {
            'social-enable-reviews' => $reviewsEnabled ? '1' : '0',
            default => '0',
        });
        $configService->method('getBool')->willReturnCallback(static fn ($value) => '1' === $value);

        return new ReviewCollectionSourceProvider($repository, $configService);
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

    // Turning the reviews off site-wide has to remove them from the pages too, not only from the management screens - a collection already pointing at the source renders empty, the registry ignoring an unknown one
    public function testNoSourceIsDeclaredWhenTheReviewsAreDisabled(): void
    {
        $this->assertSame([], $this->createProviderWith(false, $this->createReview())->getSources());
    }
}
