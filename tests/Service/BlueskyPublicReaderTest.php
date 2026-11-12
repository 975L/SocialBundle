<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Service\BlueskyPublicReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

class BlueskyPublicReaderTest extends TestCase
{
    /** @param array<string, mixed> $answer */
    private function reader(array $answer): BlueskyPublicReader
    {
        return new BlueskyPublicReader(new MockHttpClient(new JsonMockResponse($answer)));
    }

    /** @return array<string, mixed> */
    private function post(string $uri, string $link): array
    {
        return ['post' => ['uri' => $uri, 'cid' => 'cid-' . $uri, 'record' => ['text' => $link, 'facets' => [['features' => [['uri' => $link]]]]]]];
    }

    // The latest post carrying the link wins, the feed coming newest first
    public function testTheLatestPostWithTheLinkIsFound(): void
    {
        $found = $this->reader(['feed' => [$this->post('new', 'https://example.org/a'), $this->post('old', 'https://example.org/a')]])->findPostWithLink('a.bsky.social', 'https://example.org/a');

        $this->assertSame(['uri' => 'new', 'cid' => 'cid-new'], $found);
    }

    // What the account reposted is another account's post, even newer and with the same link
    public function testARepostInTheFeedIsSkipped(): void
    {
        $found = $this->reader(['feed' => [[...$this->post('theirs', 'https://example.org/a'), 'reason' => ['$type' => 'app.bsky.feed.defs#reasonRepost']], $this->post('own', 'https://example.org/a')]])->findPostWithLink('a.bsky.social', 'https://example.org/a');

        $this->assertSame(['uri' => 'own', 'cid' => 'cid-own'], $found);
    }

    // An address merely starting the same is another content
    public function testALongerAddressIsNotTheLink(): void
    {
        $this->assertNull($this->reader(['feed' => [$this->post('other', 'https://example.org/a-b')]])->findPostWithLink('a.bsky.social', 'https://example.org/a'));
    }

    public function testTheRepliesAreCounted(): void
    {
        $this->assertSame(2, $this->reader(['posts' => [['replyCount' => 2]]])->replyCount('uri'));
    }

    // The newest replies first, a deleted one left out, the handle standing for a missing display name
    public function testTheLatestRepliesAreReadNewestFirst(): void
    {
        $replies = $this->reader(['thread' => ['replies' => [
            ['post' => ['author' => ['handle' => 'a.bsky.social', 'displayName' => 'Ann'], 'record' => ['text' => 'Old', 'createdAt' => '2026-11-01T10:00:00Z']]],
            ['$type' => 'app.bsky.feed.defs#notFoundPost', 'uri' => 'gone'],
            ['post' => ['author' => ['handle' => 'b.bsky.social'], 'record' => ['text' => 'New', 'createdAt' => '2026-11-02T10:00:00Z']]],
        ]]])->latestReplies('uri', 5);

        $this->assertSame([['b.bsky.social', 'New'], ['Ann', 'Old']], array_map(static fn ($reply): array => [$reply->author, $reply->text], $replies));
    }

    // A post not shown says nothing of its replies, its count staying as it was
    public function testAPostNotShownThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->reader(['posts' => []])->replyCount('uri');
    }
}
