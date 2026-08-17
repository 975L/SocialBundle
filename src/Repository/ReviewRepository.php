<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Repository;

use c975L\SocialBundle\Entity\Review;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    // The reviews a page displays, newest first and nothing else: the ordering criterion never looks at the rating, showing a subset being defensible only when what picks it is blind to how good it is
    /**
     * @return Review[]
     */
    public function findForDisplay(?string $source = null, ?int $limit = null): array
    {
        $queryBuilder = $this->createQueryBuilder('r')
            ->orderBy('r.publishedAt', 'DESC')
        ;

        if (null !== $source) {
            $queryBuilder
                ->andWhere('r.source = :source')
                ->setParameter('source', $source)
            ;
        }

        if (null !== $limit) {
            $queryBuilder->setMaxResults($limit);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    // Total and average over every review held, never over the subset displayed - the figure a visitor reads has to cover the whole, or the subset above turns into a selection
    /**
     * @return array{count: int, average: float|null}
     */
    public function getAggregate(?string $source = null): array
    {
        $queryBuilder = $this->createQueryBuilder('r')
            ->select('COUNT(r.id) AS total, AVG(r.rating) AS average')
        ;

        if (null !== $source) {
            $queryBuilder
                ->andWhere('r.source = :source')
                ->setParameter('source', $source)
            ;
        }

        $result = $queryBuilder->getQuery()->getSingleResult();
        $count = (int) $result['total'];

        return [
            'count' => $count,
            'average' => 0 === $count ? null : round((float) $result['average'], 1),
        ];
    }

    // The row a sync updates rather than duplicates, matched on what the source itself calls this review
    public function findOneFromSource(string $source, string $externalId): ?Review
    {
        return $this->findOneBy(['source' => $source, 'externalId' => $externalId]);
    }
}
