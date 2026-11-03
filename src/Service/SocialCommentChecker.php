<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\SocialBundle\Contract\CommentCountPublisherInterface;
use c975L\SocialBundle\Contract\NetworkPublisherInterface;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Enum\SocialPostStatus;
use c975L\SocialBundle\Repository\SocialPostRepository;
use Psr\Log\LoggerInterface;

// Reads how many comments the posts gone out lately hold, on the networks able to say it, against what the last check saw - networks telling nothing by email being the reason for it all
class SocialCommentChecker
{
    // How long after going out a post's first reading counts from nothing: the task's three hours and a margin, an older post only setting where its count starts, so that turning the alert on sends no burst
    private const string FIRST_READING_WINDOW = '-4 hours';

    /** @param iterable<NetworkPublisherInterface> $networks */
    public function __construct(
        private readonly iterable $networks,
        private readonly SocialPostRepository $postRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    // Every post read of the last $days days and what it holds now. A network refusing leaves its post for the next check
    /** @return list<array{target: SocialPostTarget, count: int, added: int, url: ?string}> */
    public function read(int $days): array
    {
        $networks = $this->countingNetworks();
        $now = new \DateTimeImmutable();
        $since = $now->modify(sprintf('-%d days', $days));
        $read = [];
        foreach ($this->postRepository->findPublishedBetween($since, $now) as $post) {
            foreach ($post->getTargets() as $target) {
                $network = $networks[$target->getNetwork()] ?? null;
                if (null === $network || !$this->isToRead($target, $since)) {
                    continue;
                }

                try {
                    $comments = $network->commentCount((string) $target->getExternalId());
                } catch (\Throwable $exception) {
                    $this->logger->warning('Social comments not read on {network}: {message}', ['network' => $target->getNetwork(), 'message' => $exception->getMessage()]);
                    continue;
                }

                $read[] = ['target' => $target, 'count' => $comments->count, 'added' => $this->added($target, $comments->count, $now), 'url' => $comments->url];
            }
        }

        return $read;
    }

    // What is new since the last reading - on the first one, everything for a post just out, nothing for an older one
    private function added(SocialPostTarget $target, int $count, \DateTimeImmutable $now): int
    {
        $seen = $target->getCommentCount();
        if (null !== $seen) {
            return max(0, $count - $seen);
        }

        return $target->getPublishedAt() >= $now->modify(self::FIRST_READING_WINDOW) ? $count : 0;
    }

    // A post gone out within the delay, a repost's comments being the ones of another account
    private function isToRead(SocialPostTarget $target, \DateTimeImmutable $since): bool
    {
        return SocialPostStatus::Published === $target->getStatus()
            && null !== $target->getExternalId()
            && null === $target->getRepost()
            && $target->getPublishedAt() >= $since;
    }

    // The networks connected that say how many comments a post holds, by name
    /** @return array<string, CommentCountPublisherInterface> */
    private function countingNetworks(): array
    {
        $networks = [];
        foreach ($this->networks as $network) {
            if ($network instanceof CommentCountPublisherInterface && $network->isConfigured()) {
                $networks[$network->getName()] = $network;
            }
        }

        return $networks;
    }
}
