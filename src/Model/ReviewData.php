<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Model;

// One review as a source hands it over, before ReviewSynchronizer turns it into a Review row - normalized here so a source only ever has to translate its own vocabulary, never to know how reviews are stored
readonly class ReviewData
{
    public function __construct(
        public string $externalId,
        public ?string $authorName,
        public int $rating,
        public \DateTimeImmutable $publishedAt,
        public ?string $comment = null,
        public ?string $authorAvatarUrl = null,
        public ?string $replyComment = null,
        public ?\DateTimeImmutable $repliedAt = null,
        public ?string $sourceUrl = null,
        public bool $verified = true,
    ) {
    }
}
