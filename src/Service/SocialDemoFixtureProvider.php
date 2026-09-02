<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\UiBundle\Contract\DemoFixtureProviderInterface;
use c975L\UiBundle\Entity\Review;
use c975L\UiBundle\Enum\ReviewStatus;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The reviews a demo site shows as coming back from Google, which is what this bundle brings a site.
 *
 * The entity is UiBundle's, the rows are this bundle's: they carry the source it syncs, an identifier only that
 * platform hands out and the deep link its attribution rules demand - none of which UiBundle has any business
 * inventing. A demo installed without this bundle then shows a site collecting its own reviews and nothing more.
 *
 * Published rather than pending: an imported review is already public where it was written, and the screen exists
 * to answer it, not to let it through. One of the two is left unanswered - it is the row the guided project opens.
 */
class SocialDemoFixtureProvider implements DemoFixtureProviderInterface
{
    // Written down rather than taken from the clock, so a reloaded demo does not have "two weeks ago" say something else in every take of the same recorded sequence
    private const string POSTED_ANSWERED = '2026-01-16 11:30:00';
    private const string POSTED_WAITING = '2026-02-22 16:48:00';
    private const string REPLIED = '2026-01-17 09:05:00';

    // What a Google review's deep link looks like, the identifier being the only part that differs from one row to the next
    private const string SOURCE_URL = 'https://search.google.com/local/reviews?placeid=demo-c975l#%s';

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getDemoFixtures(): iterable
    {
        yield $this->review('answered', 5, self::POSTED_ANSWERED)
            ->setReplyComment($this->trans('label.social_sample_review_answered_reply'))
            ->setRepliedAt(new \DateTimeImmutable(self::REPLIED));

        yield $this->review('waiting', 3, self::POSTED_WAITING);
    }

    private function review(string $key, int $rating, string $postedAt): Review
    {
        return new Review()
            ->setSource(GoogleBusinessProfileSource::NAME)
            // What makes a re-sync update the row rather than write it twice; made up here, no import having run
            ->setExternalId('demo-' . $key)
            ->setStatus(ReviewStatus::Published)
            ->setAuthorName($this->trans('label.social_sample_review_' . $key . '_author'))
            ->setRating($rating)
            ->setComment($this->trans('label.social_sample_review_' . $key . '_comment'))
            ->setPublishedAt(new \DateTimeImmutable($postedAt))
            ->setSourceUrl(sprintf(self::SOURCE_URL, $key))
            // A platform only publishes what it ties to an account, which is what the site is allowed to say of it
            ->setVerified(true)
        ;
    }

    private function trans(string $key): string
    {
        return $this->translator->trans($key, [], 'social');
    }
}
