<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Contract\CommentCountPublisherInterface;
use c975L\SocialBundle\Contract\NetworkPublisherInterface;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Model\CommentCount;
use c975L\SocialBundle\Model\PostComment;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Service\SocialCommentChecker;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SocialCommentCheckerTest extends TestCase
{
    private function target(SocialPost $post, string $network = 'bluesky', ?int $seen = null, string $publishedAt = '-1 day'): SocialPostTarget
    {
        $target = new SocialPostTarget($post, $network, 'Text');
        $target->markPublished('id-' . $network);
        new \ReflectionProperty($target, 'publishedAt')->setValue($target, new \DateTimeImmutable($publishedAt));
        if (null !== $seen) {
            $target->setCommentCount($seen);
        }

        return $target;
    }

    private function post(): SocialPost
    {
        return new SocialPost(SocialPost::SOURCE_MANUAL, 'id', 'Title', 'https://example.org/a', null, new \DateTimeImmutable('-1 day'));
    }

    // $texts the comments the network quotes, a Throwable when it refuses them
    /** @param list<PostComment>|\Throwable $texts */
    private function checker(SocialPost $post, ?\Throwable $failure = null, int $count = 3, array | \Throwable $texts = [], ?int &$limit = null): SocialCommentChecker
    {
        $network = $this->createStubForIntersectionOfInterfaces([NetworkPublisherInterface::class, CommentCountPublisherInterface::class]);
        $network->method('getName')->willReturn('bluesky');
        $network->method('isConfigured')->willReturn(true);
        if (null === $failure) {
            $network->method('commentCount')->willReturn(new CommentCount($count, 'https://example.org/post'));
        } else {
            $network->method('commentCount')->willThrowException($failure);
        }

        if ($texts instanceof \Throwable) {
            $network->method('latestComments')->willThrowException($texts);
        } else {
            $network->method('latestComments')->willReturnCallback(static function (string $id, int $asked) use ($texts, &$limit): array {
                $limit = $asked;

                return $texts;
            });
        }

        $repository = $this->createStub(SocialPostRepository::class);
        $repository->method('findPublishedBetween')->willReturn([$post]);

        return new SocialCommentChecker([$network], $repository, new NullLogger());
    }

    // What the first reading of an older post finds is no news, only what it remembers
    public function testTheFirstReadingOfAnOlderPostAnnouncesNothing(): void
    {
        $post = $this->post();
        $this->target($post);

        $read = $this->checker($post)->read(30);

        $this->assertSame(0, $read[0]['added']);
        $this->assertSame(3, $read[0]['count']);
    }

    // A post just out counts from nothing, its first hours being the ones holding the most comments
    public function testTheFirstReadingOfAPostJustOutAnnouncesItsComments(): void
    {
        $post = $this->post();
        $this->target($post, publishedAt: '-2 hours');

        $this->assertSame(3, $this->checker($post)->read(30)[0]['added']);
    }

    public function testOnlyTheCommentsSinceTheLastCheckAreNew(): void
    {
        $post = $this->post();
        $this->target($post, seen: 1);

        $this->assertSame(2, $this->checker($post)->read(30)[0]['added']);
    }

    // A comment removed is no new one
    public function testFewerCommentsAreNotNew(): void
    {
        $post = $this->post();
        $this->target($post, seen: 5);

        $this->assertSame(0, $this->checker($post)->read(30)[0]['added']);
    }

    // A repost's comments are the other account's, a network saying nothing is skipped
    public function testRepostsAndOtherNetworksAreNotRead(): void
    {
        $post = $this->post();
        $this->target($post)->setRepost('other.bsky.social');
        $this->target($post, 'linkedin');

        $this->assertSame([], $this->checker($post)->read(30));
    }

    // A refusal leaves the post for the next check, without stopping it
    public function testARefusalSkipsThePost(): void
    {
        $post = $this->post();
        $this->target($post);

        $this->assertSame([], $this->checker($post, new \RuntimeException('Down'))->read(30));
    }

    // The text of the new ones only, no more than QUOTED asked
    public function testTheNewCommentsAreQuoted(): void
    {
        $post = $this->post();
        $this->target($post, seen: 0);
        $text = new PostComment('Ann', 'Nice');

        $read = $this->checker($post, count: 9, texts: [$text], limit: $limit)->read(30);

        $this->assertSame([$text], $read[0]['comments']);
        $this->assertSame(SocialCommentChecker::QUOTED, $limit);
    }

    // Nothing new, nothing asked
    public function testNoTextIsAskedWithoutNews(): void
    {
        $post = $this->post();
        $this->target($post, seen: 3);

        $read = $this->checker($post, texts: [new PostComment('Ann', 'Nice')], limit: $limit)->read(30);

        $this->assertSame([], $read[0]['comments']);
        $this->assertNull($limit);
    }

    // A text refused keeps the count, the email telling of the comments all the same
    public function testARefusedTextKeepsTheCount(): void
    {
        $post = $this->post();
        $this->target($post, seen: 1);

        $read = $this->checker($post, texts: new \RuntimeException('Missing permission'))->read(30);

        $this->assertSame(2, $read[0]['added']);
        $this->assertNull($read[0]['comments']);
    }
}
