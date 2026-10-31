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
use c975L\SocialBundle\Service\SocialSeriesGenerator;
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

    // The latest series of that title an import made with keys, which a keyed file takes up again rather than a later one imported without any - null when there is none
    public function findLastKeyedImport(string $title): ?SocialSeries
    {
        /* @var ?SocialSeries */
        return $this->createQueryBuilder('s')
            ->innerJoin(SocialPost::class, 'p', 'ON', 'p.series = s AND p.importKey IS NOT NULL')
            ->where('s.title = :title')
            ->andWhere('s.mode = :mode')
            ->setParameter('title', $title)
            ->setParameter('mode', SocialSeriesGenerator::MODE_IMPORT)
            ->orderBy('s.id', \SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    // Every series with the moment of its last post, the one ending first first, the ones whose posts were all deleted left out - what the ending announcement and the series' page go through
    /** @return list<array{series: SocialSeries, lastPlannedAt: \DateTimeImmutable}> */
    public function findWithLastPlannedAt(): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s AS series', 'MAX(p.plannedAt) AS lastPlannedAt')
            ->innerJoin(SocialPost::class, 'p', 'ON', 'p.series = s')
            ->groupBy('s.id')
            ->orderBy('lastPlannedAt', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): array => ['series' => $row['series'], 'lastPlannedAt' => new \DateTimeImmutable((string) $row['lastPlannedAt'])], $rows);
    }
}
