<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\UiBundle\Contract\SocialContentStatusProviderInterface;
use c975L\UiBundle\Model\SocialContentStatus;

// Tells the bundles owning the contents which ones a post holds (see SocialContentStatusProviderInterface), as its own content or through a media picked from one: reserved at its planned moment until it goes out on a network, published at that moment after. A media taken off a post frees its content, nothing being kept of it
class SocialContentStatusProvider implements SocialContentStatusProviderInterface
{
    public function __construct(
        private readonly SocialPostRepository $postRepository,
    ) {
    }

    // A content held by several posts - a story told again a month later - reads as its latest, one gone out staying published whatever a later post reserves it for
    public function getStatuses(string $sourceType, array $sourceIds): array
    {
        $statuses = [];
        foreach ($this->postRepository->findBySourceIds($sourceType, $sourceIds) as $post) {
            $publishedAt = array_filter($post->getTargets()->map(static fn (SocialPostTarget $target): ?\DateTimeImmutable => $target->getPublishedAt())->getValues());
            $status = [] === $publishedAt
                ? new SocialContentStatus(SocialContentStatus::RESERVED, $post->getPlannedAt())
                : new SocialContentStatus(SocialContentStatus::PUBLISHED, min($publishedAt));
            foreach (array_intersect($this->heldIds($post, $sourceType), $sourceIds) as $sourceId) {
                if ($status->isPublished() || true !== ($statuses[$sourceId] ?? null)?->isPublished()) {
                    $statuses[$sourceId] = $status;
                }
            }
        }

        return $statuses;
    }

    // The contents of a source a post holds: its own, and the ones its medias were picked from
    /** @return list<string> */
    private function heldIds(SocialPost $post, string $sourceType): array
    {
        $ids = $post->getSourceType() === $sourceType ? [$post->getSourceId()] : [];
        foreach ($post->getMedias() as $media) {
            if ($media->getSourceType() === $sourceType && null !== $media->getSourceId()) {
                $ids[] = $media->getSourceId();
            }
        }

        return $ids;
    }
}
