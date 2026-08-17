<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\SocialBundle\Contract\ReviewsReplySourceInterface;
use c975L\SocialBundle\Contract\ReviewsSourceInterface;
use c975L\SocialBundle\Entity\Review;

// Pushes an answer written in the back office back to the platform it belongs to - the only write a review ever gets, its text and its rating being the author's
class ReviewReplyPublisher
{
    /**
     * @param iterable<ReviewsSourceInterface> $sources
     */
    public function __construct(private readonly iterable $sources)
    {
    }

    // Whether this review's own source can be answered at all, which is what tells the admin screen to offer the field
    public function supports(Review $review): bool
    {
        return null !== $this->sourceOf($review);
    }

    // Publishes first and lets the exception through: a reply stored here but refused by the platform would show the visitor an answer the author never received
    public function publish(Review $review): void
    {
        $source = $this->sourceOf($review);

        if (null === $source) {
            throw new \RuntimeException(sprintf('The source "%s" does not accept replies.', $review->getSource()));
        }

        $source->reply($review->getExternalId(), $review->getReplyComment());
    }

    private function sourceOf(Review $review): ?ReviewsReplySourceInterface
    {
        foreach ($this->sources as $source) {
            if ($source instanceof ReviewsReplySourceInterface && $source->getName() === $review->getSource()) {
                return $source;
            }
        }

        return null;
    }
}
