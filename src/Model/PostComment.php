<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Model;

// One comment of a post as its network shows it, what the email of the new comments quotes so that it is read without opening the post
final readonly class PostComment
{
    public function __construct(
        public string $author,
        public string $text,
        public ?\DateTimeImmutable $publishedAt = null,
    ) {
    }

    // The newest first, a comment with no date last - every network answering in an order of its own
    /**
     * @param list<self> $comments
     *
     * @return list<self>
     */
    public static function newest(array $comments, int $limit): array
    {
        usort($comments, static fn (self $a, self $b): int => ($b->publishedAt?->getTimestamp() ?? 0) <=> ($a->publishedAt?->getTimestamp() ?? 0));

        return \array_slice($comments, 0, max(0, $limit));
    }

    // A date a network gives, none when it gives nothing readable
    public static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
