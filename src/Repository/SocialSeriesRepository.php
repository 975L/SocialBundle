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
use c975L\SocialBundle\Entity\SocialSeries;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialSeries>
 */
class SocialSeriesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialSeries::class);
    }

    // The moment of a series' last post, null for one whose posts were all deleted
    public function findLastPlannedAt(SocialSeries $series): ?\DateTimeImmutable
    {
        $last = $this->getEntityManager()->createQueryBuilder()
            ->select('MAX(p.plannedAt)')
            ->from(SocialPost::class, 'p')
            ->where('p.series = :series')
            ->setParameter('series', $series)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $last ? null : new \DateTimeImmutable((string) $last);
    }

    // Every series with the moment of its last post, the ones whose posts were all deleted left out - what the ending announcement goes through
    /** @return list<array{series: SocialSeries, lastPlannedAt: \DateTimeImmutable}> */
    public function findWithLastPlannedAt(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s AS series', 'MAX(p.plannedAt) AS lastPlannedAt')
            ->innerJoin(SocialPost::class, 'p', 'ON', 'p.series = s')
            ->groupBy('s.id')
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): array => ['series' => $row['series'], 'lastPlannedAt' => new \DateTimeImmutable((string) $row['lastPlannedAt'])], $rows);
    }
}
