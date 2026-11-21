<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Repository;

use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Entity\SocialSlot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialSlot>
 */
class SocialSlotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialSlot::class);
    }

    // The template week, by day then time
    /** @return list<SocialSlot> */
    public function findOrdered(): array
    {
        /* @var list<SocialSlot> */
        return $this->createQueryBuilder('s')
            ->orderBy('s.weekday', 'ASC')
            ->addOrderBy('s.time', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    // The slots a series is prolonged on
    /** @return list<SocialSlot> */
    public function findBySeries(SocialSeries $series): array
    {
        /* @var list<SocialSlot> */
        return $this->findBy(['series' => $series], ['id' => 'ASC']);
    }

    // The slot an imported draft names by its key
    public function findOneByKey(string $key): ?SocialSlot
    {
        return $this->findOneBy(['slotKey' => $key]);
    }
}
