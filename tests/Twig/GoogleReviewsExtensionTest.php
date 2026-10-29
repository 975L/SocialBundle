<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Twig;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Twig\GoogleReviewsExtension;
use c975L\UiBundle\Entity\Review;
use c975L\UiBundle\Repository\ReviewRepository;
use PHPUnit\Framework\TestCase;

class GoogleReviewsExtensionTest extends TestCase
{
    private function createExtension(ReviewRepository $repository, mixed $listingUrl = 'https://g.page/r/listing'): GoogleReviewsExtension
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key): mixed => 'social-google-listing-url' === $key ? $listingUrl : null);

        return new GoogleReviewsExtension($repository, $configService);
    }

    // The Google reviews alone, as ReviewItem.html.twig reads them, with the average of every displayed one rather than of the first few
    public function testTheGoogleReviewsComeWithTheAverageOfEveryDisplayedOne(): void
    {
        $review = new Review()->setSource('google')->setExternalId('a')->setAuthorName('Jean D.')->setRating(5)->setComment('Impeccable')->setVerified(false);

        $repository = $this->createMock(ReviewRepository::class);
        $repository->expects($this->once())->method('findForDisplay')->with('google', 3)->willReturn([$review]);
        $repository->expects($this->once())->method('getAggregate')->with('google')->willReturn(['count' => 12, 'average' => 4.6]);

        $reviews = $this->createExtension($repository)->getGoogleReviews(3);

        $this->assertSame(12, $reviews['count']);
        $this->assertSame(4.6, $reviews['average']);
        $this->assertSame('https://g.page/r/listing', $reviews['listingUrl']);
        $this->assertSame('Jean D.', $reviews['items'][0]['title']);
        $this->assertSame('Impeccable', $reviews['items'][0]['content']);
        $this->assertSame(5, $reviews['items'][0]['rating']);
        $this->assertFalse($reviews['items'][0]['verified']);
    }

    // Nothing to draw, nothing more asked: the block renders empty and needs no average
    public function testNoReviewAsksNoAggregate(): void
    {
        $repository = $this->createMock(ReviewRepository::class);
        $repository->method('findForDisplay')->willReturn([]);
        $repository->expects($this->never())->method('getAggregate');

        $reviews = $this->createExtension($repository)->getGoogleReviews();

        $this->assertSame([], $reviews['items']);
        $this->assertNull($reviews['average']);
    }

    // An empty or a blank listing url is no link, and a limit of nothing means them all
    public function testABlankListingUrlIsNoLinkAndNoLimitMeansAll(): void
    {
        $repository = $this->createMock(ReviewRepository::class);
        $repository->expects($this->once())->method('findForDisplay')->with('google', null)->willReturn([]);

        $this->assertNull($this->createExtension($repository, '  ')->getGoogleReviews(0)['listingUrl']);
    }
}
