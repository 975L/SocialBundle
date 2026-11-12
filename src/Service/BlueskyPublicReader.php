<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\SocialBundle\Model\PostComment;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// What Bluesky shows anyone, read on its public AppView with no token: no permission to ask, no connection to renew
class BlueskyPublicReader
{
    private const string API = 'https://public.api.bsky.app/xrpc/';

    // How far back a post to repost is looked for, the most Bluesky gives in one page
    private const int FEED_LIMIT = 100;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    // The latest post of $account carrying the link $url, read in its facets and embed so another address starting the same never matches - what the account itself reposted, written by another, left out
    /** @return array{uri: string, cid: string}|null */
    public function findPostWithLink(string $account, string $url): ?array
    {
        $needle = '"uri":' . json_encode($url, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $feed = $this->get('app.bsky.feed.getAuthorFeed', ['actor' => $account, 'limit' => self::FEED_LIMIT, 'filter' => 'posts_no_replies'])['feed'] ?? [];
        foreach ($feed as $item) {
            if (isset($item['reason'])) {
                continue;
            }

            $post = $item['post'] ?? [];
            if (isset($post['uri'], $post['cid']) && str_contains((string) json_encode($post['record'] ?? [], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), $needle)) {
                return ['uri' => (string) $post['uri'], 'cid' => (string) $post['cid']];
            }
        }

        return null;
    }

    // How many replies the post $uri holds, a post not shown thrown: counted as none, its replies would be new again once it is back
    public function replyCount(string $uri): int
    {
        $post = $this->get('app.bsky.feed.getPosts', ['uris' => $uri])['posts'][0] ?? throw new \RuntimeException(sprintf('Bluesky does not show the post %s.', $uri));

        return (int) ($post['replyCount'] ?? 0);
    }

    // The $limit newest direct replies of the post $uri, a reply deleted or blocked left out
    /** @return list<PostComment> */
    public function latestReplies(string $uri, int $limit): array
    {
        $replies = [];
        foreach ($this->get('app.bsky.feed.getPostThread', ['uri' => $uri, 'depth' => 1, 'parentHeight' => 0])['thread']['replies'] ?? [] as $reply) {
            $post = $reply['post'] ?? null;
            if (!\is_array($post) || !isset($post['record']['text'])) {
                continue;
            }

            $author = $post['author'] ?? [];
            $replies[] = new PostComment((string) ($author['displayName'] ?? '') ?: (string) ($author['handle'] ?? ''), (string) $post['record']['text'], PostComment::date($post['record']['createdAt'] ?? null));
        }

        return PostComment::newest($replies, $limit);
    }

    // One public method called, any refusal thrown
    /**
     * @param array<string, int|string> $query
     *
     * @return array<string, mixed>
     */
    private function get(string $method, array $query): array
    {
        return $this->httpClient->request('GET', self::API . $method, ['query' => $query, 'timeout' => 15])->toArray();
    }
}
