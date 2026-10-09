<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\UiBundle\Contract\SocialContentStatusProviderInterface;
use c975L\UiBundle\Model\SocialContentStatus;

// Tells the bundles owning the contents which ones a post holds (see SocialContentStatusProviderInterface): reserved at its planned moment until it goes out on a network, published at that moment after
class SocialContentStatusProvider implements SocialContentStatusProviderInterface
{
    public function __construct(
        private readonly SocialPostRepository $postRepository,
    ) {
    }

    // A content held by several posts - a story told again a month later - reads as its latest
    public function getStatuses(string $sourceType, array $sourceIds): array
    {
        $statuses = [];
        foreach ($this->postRepository->findBySourceIds($sourceType, $sourceIds) as $post) {
            $publishedAt = array_filter($post->getTargets()->map(static fn (SocialPostTarget $target): ?\DateTimeImmutable => $target->getPublishedAt())->getValues());
            $statuses[$post->getSourceId()] = [] === $publishedAt
                ? new SocialContentStatus(SocialContentStatus::RESERVED, $post->getPlannedAt())
                : new SocialContentStatus(SocialContentStatus::PUBLISHED, min($publishedAt));
        }

        return $statuses;
    }
}
