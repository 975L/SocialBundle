<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Repository;

use c975L\SocialBundle\Entity\Review;
use c975L\SocialBundle\Repository\ReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

class ReviewRepositoryTest extends TestCase
{
    private string $dql = '';

    /**
     * @var array<string, mixed>
     */
    private array $result = [];

    // The query the repository builds is read back through the DQL the entity manager is handed, the rest of it being Doctrine's own
    private function createRepository(): ReviewRepository
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturnMap([
            [Review::class, new ClassMetadata(Review::class)],
        ]);
        $entityManager->method('createQueryBuilder')->willReturnCallback(fn (): QueryBuilder => new QueryBuilder($entityManager));
        $entityManager->method('createQuery')->willReturnCallback(function (string $dql): Query {
            $this->dql = $dql;

            $query = $this->createStub(Query::class);
            $query->method('setParameters')->willReturnSelf();
            $query->method('setFirstResult')->willReturnSelf();
            $query->method('setMaxResults')->willReturnSelf();
            $query->method('getResult')->willReturn([]);
            $query->method('getSingleResult')->willReturn($this->result);

            return $query;
        });

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnMap([
            [Review::class, $entityManager],
        ]);

        return new ReviewRepository($registry);
    }

    // Newest first and nothing else: showing a subset is only defensible while what picks it is blind to how good the reviews are
    public function testFindForDisplayOrdersByPublicationDateAndNeverByRating(): void
    {
        $this->createRepository()->findForDisplay();

        $this->assertStringContainsString('ORDER BY r.publishedAt DESC', $this->dql);
        $this->assertStringNotContainsString('rating', $this->dql);
    }

    public function testFindForDisplayFiltersOnTheSourceWhenOneIsGiven(): void
    {
        $this->createRepository()->findForDisplay('google');

        $this->assertStringContainsString('r.source = :source', $this->dql);
    }

    public function testFindForDisplayQueriesEverySourceWhenNoneIsGiven(): void
    {
        $this->createRepository()->findForDisplay();

        $this->assertStringNotContainsString('WHERE', $this->dql);
    }

    // The figure a visitor reads covers every review held, or the subset displayed turns into a selection
    public function testGetAggregateCountsAndAveragesOverTheWholeSource(): void
    {
        $this->result = ['total' => '12', 'average' => '4.333333'];

        $aggregate = $this->createRepository()->getAggregate();

        $this->assertSame(['count' => 12, 'average' => 4.3], $aggregate);
        $this->assertStringContainsString('COUNT(r.id)', $this->dql);
        $this->assertStringContainsString('AVG(r.rating)', $this->dql);
    }

    // No review means no average to print, where a 0 would read as the worst possible rating
    public function testGetAggregateReturnsNoAverageWhenThereIsNoReview(): void
    {
        $this->result = ['total' => '0', 'average' => null];

        $this->assertSame(['count' => 0, 'average' => null], $this->createRepository()->getAggregate());
    }

    public function testGetAggregateFiltersOnTheSourceWhenOneIsGiven(): void
    {
        $this->result = ['total' => '3', 'average' => '5'];

        $this->createRepository()->getAggregate('google');

        $this->assertStringContainsString('r.source = :source', $this->dql);
    }
}
