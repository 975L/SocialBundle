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

    // The posts holding some contents of a source, their targets with them, the latest planned last - what tells the owning bundle which of its contents are reserved or gone out
    /**
     * @param list<string> $sourceIds
     *
     * @return list<SocialPost>
     */
    public function findBySourceIds(string $sourceType, array $sourceIds): array
    {
        if ([] === $sourceIds) {
            return [];
        }

        return $this->createQueryBuilder('p')
            ->leftJoin('p.targets', 't')
            ->addSelect('t')
            ->where('p.sourceType = :sourceType')
            ->andWhere('p.sourceId IN (:sourceIds)')
            ->setParameter('sourceType', $sourceType)
            ->setParameter('sourceIds', $sourceIds)
            ->orderBy('p.plannedAt', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
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

    // Every post with a text approved, its targets with it, in the order prepared - what the planned run sends once its moment has come
    /** @return list<SocialPost> */
    public function findApproved(): array
    {
        return $this->findWithTargetStatus(SocialPostStatus::Approved);
    }

    // The posts with a target published between the two moments, what the calendar shows of the past - every target loaded
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

    // The posts planned between the two moments, whatever their targets became - what the calendar lays at their moment
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

    // The posts gone out on every network they have - at least one -, planned before the moment given, that still hold a media - what the purge frees their files from
    /** @return list<SocialPost> */
    public function findPublishedWithMediasBefore(\DateTimeImmutable $before): array
    {
        /* @var list<SocialPost> */
        return $this->createQueryBuilder('p')
            ->innerJoin('p.medias', 'm')
            ->where('p.plannedAt < :before')
            ->andWhere('EXISTS (SELECT o.id FROM ' . SocialPostTarget::class . ' o WHERE o.post = p)')
            ->andWhere('NOT EXISTS (SELECT t.id FROM ' . SocialPostTarget::class . ' t WHERE t.post = p AND t.status <> :published)')
            ->setParameter('before', $before)
            ->setParameter('published', SocialPostStatus::Published)
            ->distinct()
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
