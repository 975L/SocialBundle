<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Repository;

use c975L\SocialBundle\Entity\SocialMedia;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Enum\SocialPostStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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

    // The posts holding some contents of a source - as their own content, or through a media picked from one - their targets with them, the latest planned last: what tells the owning bundle which of its contents are reserved or gone out. The medias are joined to filter only, never loaded half
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
            ->leftJoin('p.medias', 'm')
            ->where('(p.sourceType = :sourceType AND p.sourceId IN (:sourceIds)) OR (m.sourceType = :sourceType AND m.sourceId IN (:sourceIds))')
            ->setParameter('sourceType', $sourceType)
            ->setParameter('sourceIds', $sourceIds)
            ->orderBy('p.plannedAt', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    // The ids of a source already taken for a post, as its content or as one of its medias, since a date or ever - a post prepared counts whatever its networks made of it, a draft nobody published included. A media may be picked long after its post was prepared, so it also counts while that post is planned since the date
    /** @return list<string> */
    public function findSourceIds(string $sourceType, ?\DateTimeImmutable $since): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('DISTINCT p.sourceId')
            ->where('p.sourceType = :sourceType')
            ->setParameter('sourceType', $sourceType);

        $medias = $this->getEntityManager()->createQueryBuilder()
            ->select('DISTINCT m.sourceId')
            ->from(SocialMedia::class, 'm')
            ->innerJoin('m.post', 'p')
            ->where('m.sourceType = :sourceType')
            ->setParameter('sourceType', $sourceType);

        if (null !== $since) {
            $qb->andWhere('p.createdAt >= :since')->setParameter('since', $since);
            $medias->andWhere('p.createdAt >= :since OR p.plannedAt >= :since')->setParameter('since', $since);
        }

        return array_values(array_unique(array_map(strval(...), [...$qb->getQuery()->getSingleColumnResult(), ...$medias->getQuery()->getSingleColumnResult()])));
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

    // How many posts are planned before the moment: the list, in planned order, opens on the page that follows them
    public function countPlannedBefore(\DateTimeImmutable $moment): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.plannedAt < :moment')
            ->setParameter('moment', $moment)
            ->getQuery()
            ->getSingleScalarResult();
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

    // The posts of a series still to come, every target and media loaded - the medias being checked before each approval -, in their order: what "Approve the series" goes through
    /** @return list<SocialPost> */
    public function findComingBySeries(SocialSeries $series, \DateTimeImmutable $after): array
    {
        $builder = $this->createQueryBuilder('p')
            ->addSelect('t', 'm')
            ->leftJoin('p.targets', 't')
            ->leftJoin('p.medias', 'm');

        /* @var list<SocialPost> */
        return $this->comingInSeries($builder, $series, $after)->getQuery()->getResult();
    }

    // The ids of a series' posts still to come, in their order, nothing else loaded - what the panel steps through
    /** @return list<int> */
    public function findComingIdsBySeries(SocialSeries $series, \DateTimeImmutable $after): array
    {
        $ids = $this->comingInSeries($this->createQueryBuilder('p')->select('p.id'), $series, $after)->getQuery()->getSingleColumnResult();

        return array_values(array_map(intval(...), $ids));
    }

    // A series' posts still to come, in the order every screen shows them - the same moment ordered by id
    private function comingInSeries(QueryBuilder $builder, SocialSeries $series, \DateTimeImmutable $after): QueryBuilder
    {
        return $builder
            ->andWhere('p.series = :series')
            ->andWhere('p.plannedAt > :after')
            ->setParameter('series', $series)
            ->setParameter('after', $after)
            ->orderBy('p.plannedAt', \SortDirection::Ascending)
            ->addOrderBy('p.id', \SortDirection::Ascending);
    }

    // The posts planned between the two moments still waiting on a network's approval - whatever the others became, that one not going out -, in their order: what the dashboard warns of
    /** @return list<SocialPost> */
    public function findDraftsPlannedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /* @var list<SocialPost> */
        return $this->createQueryBuilder('p')
            ->where('p.plannedAt >= :from')
            ->andWhere('p.plannedAt < :to')
            ->andWhere('EXISTS (SELECT d.id FROM ' . SocialPostTarget::class . ' d WHERE d.post = p AND d.status = :draft)')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('draft', SocialPostStatus::Draft)
            ->orderBy('p.plannedAt', \SortDirection::Ascending)
            ->addOrderBy('p.id', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    // How many posts of a series still to come are drafts - waiting on a network, none approved nor refused anywhere (see SocialPost::getState()) -, counted without loading them: what "Approve the series" says it will approve
    public function countComingDraftsBySeries(SocialSeries $series, \DateTimeImmutable $after): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.series = :series')
            ->andWhere('p.plannedAt > :after')
            ->andWhere('EXISTS (SELECT d.id FROM ' . SocialPostTarget::class . ' d WHERE d.post = p AND d.status = :draft)')
            ->andWhere('NOT EXISTS (SELECT o.id FROM ' . SocialPostTarget::class . ' o WHERE o.post = p AND o.status IN (:decided))')
            ->setParameter('series', $series)
            ->setParameter('after', $after)
            ->setParameter('draft', SocialPostStatus::Draft)
            ->setParameter('decided', [SocialPostStatus::Approved->value, SocialPostStatus::Failed->value])
            ->getQuery()
            ->getSingleScalarResult();
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
