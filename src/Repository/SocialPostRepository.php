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
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Enum\SocialPostStatus;
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

    // The ids of a source already taken for a post, since a date or ever, on any of the given networks or on any at all - a post prepared counts whatever its networks made of it, a draft nobody published included
    /**
     * @param list<string> $networks
     *
     * @return list<string>
     */
    public function findSourceIds(string $sourceType, ?\DateTimeImmutable $since, array $networks = []): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('DISTINCT p.sourceId')
            ->where('p.sourceType = :sourceType')
            ->setParameter('sourceType', $sourceType);

        // A slot posting on its own networks may take what another slot sent elsewhere
        if ([] !== $networks) {
            $qb->innerJoin('p.targets', 't')->andWhere('t.network IN (:networks)')->setParameter('networks', $networks);
        }

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

    // Every post waiting in the slots' queue, its targets with it, in the order prepared - the order SocialPlanner keeps between the posts it has no planned moment to sort by
    /** @return list<SocialPost> */
    public function findApproved(): array
    {
        return $this->findWithTargetStatus(SocialPostStatus::Approved);
    }

    // Every post with a text still waiting for a reading, in the order prepared
    /** @return list<SocialPost> */
    public function findDrafts(): array
    {
        return $this->findWithTargetStatus(SocialPostStatus::Draft);
    }

    // The posts with a target published between the two moments, what the calendar shows of the past - every target loaded, the same post being read again for the queue in the same request
    /** @return list<SocialPost> */
    public function findPublishedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /* @var list<SocialPost> */
        return $this->createQueryBuilder('p')
            ->addSelect('t')
            ->innerJoin('p.targets', 't')
            ->innerJoin('p.targets', 'm')
            ->where('m.status = :published')
            ->andWhere('m.publishedAt >= :from')
            ->andWhere('m.publishedAt < :to')
            ->setParameter('published', SocialPostStatus::Published)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();
    }

    // The posts planned between the two moments, whatever their targets became - a slot standing aside for one the planned run already sent
    /** @return list<SocialPost> */
    public function findPlannedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /* @var list<SocialPost> */
        return $this->createQueryBuilder('p')
            ->addSelect('t')
            ->leftJoin('p.targets', 't')
            ->where('p.plannedAt >= :from')
            ->andWhere('p.plannedAt < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();
    }

    // The last texts the site published on a network, newest first - the tone SocialPostWriter is shown
    /** @return list<string> */
    public function findPublishedTexts(string $network, int $limit): array
    {
        $texts = $this->getEntityManager()->createQueryBuilder()
            ->select('t.text')
            ->from(SocialPostTarget::class, 't')
            ->where('t.network = :network')
            ->andWhere('t.status = :published')
            ->setParameter('network', $network)
            ->setParameter('published', SocialPostStatus::Published)
            ->orderBy('t.publishedAt', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(strval(...), $texts));
    }

    // Joined through a second alias, so every target of the post is loaded and not only the ones matching
    /** @return list<SocialPost> */
    private function findWithTargetStatus(SocialPostStatus $status): array
    {
        /* @var list<SocialPost> */
        return $this->createQueryBuilder('p')
            ->addSelect('t')
            ->innerJoin('p.targets', 't')
            ->innerJoin('p.targets', 'm')
            ->where('m.status = :status')
            ->setParameter('status', $status)
            ->orderBy('p.createdAt', \SortDirection::Ascending)
            ->addOrderBy('p.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }
}
