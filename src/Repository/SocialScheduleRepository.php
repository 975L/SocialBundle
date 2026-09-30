<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Repository;

use c975L\SocialBundle\Entity\SocialSchedule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialSchedule>
 */
class SocialScheduleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialSchedule::class);
    }

    // The slots to schedule, by time - what SocialMaintenanceTaskProvider turns into tasks
    /** @return list<SocialSchedule> */
    public function findEnabled(): array
    {
        return $this->findBy(['enabled' => true], ['time' => 'ASC']);
    }

    // Whether any slot is on, the hourly run on the interval standing aside then
    public function hasEnabled(): bool
    {
        // A site whose migration has not created the table yet keeps posting on the interval
        try {
            return null !== $this->findOneBy(['enabled' => true]);
        } catch (\Throwable) {
            return false;
        }
    }
}
