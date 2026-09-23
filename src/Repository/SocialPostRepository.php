<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Repository;

use c975L\SocialBundle\Entity\SocialPost;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialPost>
 */
class SocialPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialPost::class);
    }

    // The ids of a source already taken for a post, since a date or ever - a post prepared counts whatever its networks made of it, a draft nobody published included
    /** @return list<string> */
    public function findSourceIds(string $sourceType, ?\DateTimeImmutable $since): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('DISTINCT p.sourceId')
            ->where('p.sourceType = :sourceType')
            ->setParameter('sourceType', $sourceType);

        if (null !== $since) {
            $qb->andWhere('p.createdAt >= :since')->setParameter('since', $since);
        }

        return array_values(array_map(strval(...), $qb->getQuery()->getSingleColumnResult()));
    }

    // When the last post was prepared, null before the first one - what the scheduled run compares its interval to. Prepared rather than published: a site reviewing its posts would otherwise get a new draft every hour until it published one
    public function findLastCreatedAt(): ?\DateTimeImmutable
    {
        return $this->findOneBy([], ['createdAt' => 'DESC'])?->getCreatedAt();
    }

    // When each source type last had a post prepared, the ones never taken being absent - so the sources take turns rather than the first declared one always winning
    /** @return array<string, string> */
    public function findLastCreatedAtBySourceType(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.sourceType AS sourceType', 'MAX(p.createdAt) AS lastCreatedAt')
            ->groupBy('p.sourceType')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'lastCreatedAt', 'sourceType');
    }
}
