<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\SocialBundle\Contract\ReviewsSourceInterface;
use c975L\SocialBundle\Entity\Review;
use c975L\SocialBundle\Model\ReviewData;
use c975L\SocialBundle\Repository\ReviewRepository;
use Doctrine\ORM\EntityManagerInterface;

// Pulls every configured source into the Review table - the one place that writes reviews, so a source only ever reads its platform and hands back ReviewData
class ReviewSynchronizer
{
    /**
     * @param iterable<ReviewsSourceInterface> $sources
     */
    public function __construct(
        private readonly iterable $sources,
        private readonly ReviewRepository $reviewRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    // How many reviews each source imported, keyed by source name; an unconfigured source is skipped rather than failing the run, a site having no reason to configure them all
    /**
     * @return array<string, int>
     */
    public function synchronize(?string $only = null): array
    {
        $imported = [];

        foreach ($this->sources as $source) {
            $name = $source->getName();

            if ((null !== $only && $only !== $name) || !$source->isConfigured()) {
                continue;
            }

            $imported[$name] = $this->synchronizeSource($source);

            // One flush per source: a single one after the loop would lose everything the previous sources imported as soon as the next one fails
            $this->entityManager->flush();
        }

        return $imported;
    }

    // Upserts one source's reviews on its own external ids, so re-running only ever updates what changed
    private function synchronizeSource(ReviewsSourceInterface $source): int
    {
        $name = $source->getName();
        $count = 0;
        $seen = [];

        foreach ($source->fetch() as $data) {
            // Google paginates on the mutable updateTime, so the same review can come back on two pages of one run - persisting it twice would break the whole flush on its unique source/externalId. The first pass is the freshest, hence the skip
            if (isset($seen[$data->externalId])) {
                continue;
            }

            $seen[$data->externalId] = true;

            $review = $this->reviewRepository->findOneFromSource($name, $data->externalId) ?? new Review();
            $review->setSource($name);

            $this->fill($review, $data);
            $this->entityManager->persist($review);
            ++$count;
        }

        return $count;
    }

    // The source stays authoritative on every field, reply included: a reply removed on the platform has to disappear here too, or the site keeps showing an answer its author has withdrawn
    private function fill(Review $review, ReviewData $data): void
    {
        $review
            ->setExternalId($data->externalId)
            ->setAuthorName($data->authorName)
            ->setAuthorAvatarUrl($data->authorAvatarUrl)
            ->setRating($data->rating)
            ->setComment($data->comment)
            ->setPublishedAt($data->publishedAt)
            ->setReplyComment($data->replyComment)
            ->setRepliedAt($data->repliedAt)
            ->setSourceUrl($data->sourceUrl)
            ->setVerified($data->verified)
        ;
    }
}
