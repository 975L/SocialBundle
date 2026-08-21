<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Contract\ReviewsReplySourceInterface;
use c975L\SocialBundle\Contract\ReviewsSourceInterface;
use c975L\SocialBundle\Entity\Review;
use c975L\SocialBundle\Service\ReviewReplyPublisher;
use PHPUnit\Framework\TestCase;

class ReviewReplyPublisherTest extends TestCase
{
    /**
     * @param array{0: string, 1: ?string}|null $published
     */
    private function createReplySource(string $name, ?array &$published, bool $configured = true): ReviewsReplySourceInterface
    {
        return new class ($name, $published, $configured) implements ReviewsReplySourceInterface {
            public function __construct(private readonly string $name, private ?array &$published, private readonly bool $configured)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function fetch(): iterable
            {
                return [];
            }

            public function reply(string $externalId, ?string $comment): void
            {
                $this->published = [$externalId, $comment];
            }
        };
    }

    private function createReadOnlySource(string $name): ReviewsSourceInterface
    {
        return new readonly class ($name) implements ReviewsSourceInterface {
            public function __construct(private string $name)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function fetch(): iterable
            {
                return [];
            }
        };
    }

    private function createReview(string $source, ?string $reply = 'Merci !'): Review
    {
        return new Review()->setSource($source)->setExternalId('r1')->setReplyComment($reply);
    }

    public function testPublishSendsTheReplyToTheReviewsOwnSource(): void
    {
        $published = null;
        $publisher = new ReviewReplyPublisher([$this->createReplySource('google', $published)]);

        $publisher->publish($this->createReview('google'));

        $this->assertSame(['r1', 'Merci !'], $published);
    }

    // A source that cannot be answered must say so, so the screen never offers a field whose save would fail
    public function testSupportsIsFalseForAReadOnlySource(): void
    {
        $publisher = new ReviewReplyPublisher([$this->createReadOnlySource('imported')]);

        $this->assertFalse($publisher->supports($this->createReview('imported')));
    }

    // A revoked token has to close the field too, or saving a reply answers a raw 500 where every other failure gets a flash message
    public function testSupportsIsFalseForADisconnectedSource(): void
    {
        $published = null;
        $publisher = new ReviewReplyPublisher([$this->createReplySource('google', $published, false)]);

        $this->assertFalse($publisher->supports($this->createReview('google')));
    }

    public function testPublishThrowsWhenNoSourceOwnsTheReview(): void
    {
        $published = null;
        $publisher = new ReviewReplyPublisher([$this->createReplySource('google', $published)]);

        $this->expectException(\RuntimeException::class);

        $publisher->publish($this->createReview('elsewhere'));
    }
}
