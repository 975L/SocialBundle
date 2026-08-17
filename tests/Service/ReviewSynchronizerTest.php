<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Contract\ReviewsSourceInterface;
use c975L\SocialBundle\Entity\Review;
use c975L\SocialBundle\Model\ReviewData;
use c975L\SocialBundle\Repository\ReviewRepository;
use c975L\SocialBundle\Service\ReviewSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ReviewSynchronizerTest extends TestCase
{
    private function createSource(string $name, bool $configured, ReviewData ...$reviews): ReviewsSourceInterface
    {
        return new readonly class ($name, $configured, $reviews) implements ReviewsSourceInterface {
            /**
             * @param ReviewData[] $reviews
             */
            public function __construct(
                private string $name,
                private bool $configured,
                private array $reviews,
            ) {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function fetch(): iterable
            {
                return $this->reviews;
            }
        };
    }

    private function createData(string $externalId, int $rating = 5): ReviewData
    {
        return new ReviewData(
            externalId: $externalId,
            authorName: 'Jean D.',
            rating: $rating,
            publishedAt: new \DateTimeImmutable('2026-08-01'),
            comment: 'Impeccable',
        );
    }

    public function testSynchronizePersistsOneReviewPerFetchedItem(): void
    {
        $repository = $this->createStub(ReviewRepository::class);
        $repository->method('findOneFromSource')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->exactly(2))->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $synchronizer = new ReviewSynchronizer(
            [$this->createSource('google', true, $this->createData('a'), $this->createData('b'))],
            $repository,
            $entityManager
        );

        $this->assertSame(['google' => 2], $synchronizer->synchronize());
    }

    // A second run must update the row the source already gave, never add a duplicate next to it
    public function testSynchronizeUpdatesTheExistingRowOfAKnownExternalId(): void
    {
        $existing = new Review()->setSource('google')->setExternalId('a')->setRating(1)->setAuthorName('Old');

        $repository = $this->createStub(ReviewRepository::class);
        $repository->method('findOneFromSource')->willReturn($existing);

        $synchronizer = new ReviewSynchronizer(
            [$this->createSource('google', true, $this->createData('a', 4))],
            $repository,
            $this->createStub(EntityManagerInterface::class)
        );
        $synchronizer->synchronize();

        $this->assertSame(4, $existing->getRating());
        $this->assertSame('Jean D.', $existing->getAuthorName());
    }

    // A single flush after the loop would lose everything the earlier sources imported as soon as a later one fails
    public function testSynchronizeFlushesOncePerSource(): void
    {
        $repository = $this->createStub(ReviewRepository::class);
        $repository->method('findOneFromSource')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->exactly(2))->method('flush');

        $synchronizer = new ReviewSynchronizer(
            [
                $this->createSource('google', true, $this->createData('a')),
                $this->createSource('other', true, $this->createData('b')),
            ],
            $repository,
            $entityManager
        );

        $this->assertSame(['google' => 1, 'other' => 1], $synchronizer->synchronize());
    }

    // Google paginates on the mutable updateTime, so one run can hand the same review back on two pages - persisting it twice would break the flush on its unique source/externalId
    public function testSynchronizeIgnoresAnExternalIdAlreadySeenInTheRun(): void
    {
        $repository = $this->createStub(ReviewRepository::class);
        $repository->method('findOneFromSource')->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('persist');

        $synchronizer = new ReviewSynchronizer(
            [$this->createSource('google', true, $this->createData('a'), $this->createData('a', 3))],
            $repository,
            $entityManager
        );

        $this->assertSame(['google' => 1], $synchronizer->synchronize());
    }

    // A site configures the sources it uses and no others, so the rest must be stepped over rather than break the cron
    public function testSynchronizeSkipsAnUnconfiguredSource(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('persist');

        $synchronizer = new ReviewSynchronizer(
            [$this->createSource('google', false, $this->createData('a'))],
            $this->createStub(ReviewRepository::class),
            $entityManager
        );

        $this->assertSame([], $synchronizer->synchronize());
    }

    public function testSynchronizeRestrictedToOneSourceLeavesTheOthersAlone(): void
    {
        $repository = $this->createStub(ReviewRepository::class);
        $repository->method('findOneFromSource')->willReturn(null);

        $synchronizer = new ReviewSynchronizer(
            [
                $this->createSource('google', true, $this->createData('a')),
                $this->createSource('other', true, $this->createData('b')),
            ],
            $repository,
            $this->createStub(EntityManagerInterface::class)
        );

        $this->assertSame(['other' => 1], $synchronizer->synchronize('other'));
    }

    // The platform stays authoritative: a reply withdrawn there has to disappear here, or the site keeps showing an answer its author removed
    public function testSynchronizeClearsAReplyTheSourceNoLongerReturns(): void
    {
        $existing = new Review()->setSource('google')->setExternalId('a')->setReplyComment('Merci !');

        $repository = $this->createStub(ReviewRepository::class);
        $repository->method('findOneFromSource')->willReturn($existing);

        $synchronizer = new ReviewSynchronizer(
            [$this->createSource('google', true, $this->createData('a'))],
            $repository,
            $this->createStub(EntityManagerInterface::class)
        );
        $synchronizer->synchronize();

        $this->assertNull($existing->getReplyComment());
    }
}
