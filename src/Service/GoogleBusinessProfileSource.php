<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Contract\ReviewsReplySourceInterface;
use c975L\SocialBundle\Model\ReviewData;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// The reviews of the Google listing the site was connected to. Still on the v4 host: the reviews endpoints were never moved to the split Business Profile APIs, and are only reachable once the Cloud project has been allowlisted
class GoogleBusinessProfileSource implements ReviewsReplySourceInterface
{
    public const string NAME = 'google';

    private const string API_BASE = 'https://mybusiness.googleapis.com/v4';

    // Google's own maximum for this endpoint; asking for more is refused rather than clamped
    private const int PAGE_SIZE = 50;

    // starRating is an enum, not a number - a rating read as a string would store 0 for every review
    private const array RATINGS = [
        'ONE' => 1,
        'TWO' => 2,
        'THREE' => 3,
        'FOUR' => 4,
        'FIVE' => 5,
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly GoogleOAuthClient $googleOAuthClient,
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function isConfigured(): bool
    {
        return $this->googleOAuthClient->isConfigured()
            && null !== $this->accountId()
            && null !== $this->locationId();
    }

    public function fetch(): iterable
    {
        $pageToken = null;

        do {
            $payload = $this->request('GET', $this->reviewsPath(), [
                'pageSize' => self::PAGE_SIZE,
                'orderBy' => 'updateTime desc',
                ...(null !== $pageToken ? ['pageToken' => $pageToken] : []),
            ]);

            foreach ($payload['reviews'] ?? [] as $review) {
                $data = $this->toReviewData($review);

                if (null !== $data) {
                    yield $data;
                }
            }

            $pageToken = $payload['nextPageToken'] ?? null;
        } while (is_string($pageToken) && '' !== $pageToken);
    }

    public function reply(string $externalId, ?string $comment): void
    {
        $path = $this->reviewsPath() . '/' . rawurlencode($externalId) . '/reply';

        if (null === $comment || '' === trim($comment)) {
            $this->request('DELETE', $path);

            return;
        }

        $this->request('PUT', $path, body: ['comment' => $comment]);
    }

    // A review Google returns without an id or a readable rating is skipped rather than stored half-built: it could neither be updated on the next run nor displayed
    private function toReviewData(mixed $review): ?ReviewData
    {
        $externalId = $this->reviewId($review);
        $rating = $this->rating($review);
        if (null === $externalId || null === $rating) {
            return null;
        }

        [$replyComment, $repliedAt] = $this->toReply($review['reviewReply'] ?? null);
        [$authorName, $authorAvatarUrl] = $this->toAuthor($review['reviewer'] ?? null);

        return new ReviewData(
            externalId: $externalId,
            authorName: $authorName,
            rating: $rating,
            publishedAt: new \DateTimeImmutable($review['createTime'] ?? 'now'),
            comment: $review['comment'] ?? null,
            authorAvatarUrl: $authorAvatarUrl,
            replyComment: $replyComment,
            repliedAt: $repliedAt,
            // Left null on purpose: the v4 API only returns the review's resource name ("accounts/1/locations/2/reviews/3"), never a public permalink a visitor could follow
            sourceUrl: null,
            // Google ties every review to a signed-in account, which is the only verification a listing offers
            verified: true,
        );
    }

    // The id Google files the review under, and the only thing this source can key on - anything else it hands back is skipped rather than stored under a made-up key
    private function reviewId(mixed $review): ?string
    {
        return is_array($review) && isset($review['reviewId']) && is_string($review['reviewId']) ? $review['reviewId'] : null;
    }

    // The star count, which Google words rather than numbers - a wording this bundle doesn't know skips the review instead of rating it zero
    private function rating(mixed $review): ?int
    {
        return is_array($review) ? self::RATINGS[$review['starRating'] ?? ''] ?? null : null;
    }

    /**
     * Left null when Google hands back no display name: naming the author is the template's call, and a label stored here would be frozen in the locale of the import.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function toAuthor(mixed $reviewer): array
    {
        if (!is_array($reviewer)) {
            return [null, null];
        }

        return [$reviewer['displayName'] ?? null, $reviewer['profilePhotoUrl'] ?? null];
    }

    /**
     * What the owner answered, if they answered at all - a review Google hands back without a reply carries neither of the two.
     *
     * @return array{0: string|null, 1: \DateTimeImmutable|null}
     */
    private function toReply(mixed $reply): array
    {
        if (!is_array($reply)) {
            return [null, null];
        }

        return [
            $reply['comment'] ?? null,
            isset($reply['updateTime']) ? new \DateTimeImmutable($reply['updateTime']) : null,
        ];
    }

    private function reviewsPath(): string
    {
        return sprintf('/accounts/%s/locations/%s/reviews', (string) $this->accountId(), (string) $this->locationId());
    }

    /**
     * @param array<string, mixed>      $query
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $response = $this->httpClient->request($method, self::API_BASE . $path, [
            'auth_bearer' => $this->googleOAuthClient->getAccessToken(),
            'query' => $query,
            ...(null !== $body ? ['json' => $body] : []),
            'timeout' => 30,
        ]);

        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException(sprintf('Google answered %d on %s: %s', $response->getStatusCode(), $path, $response->getContent(false)));
        }

        // A DELETE answers with an empty body, which is a success and not an unreadable payload
        $content = $response->getContent(false);

        if ('' === trim($content)) {
            return [];
        }

        $payload = json_decode($content, true);

        if (!is_array($payload)) {
            throw new \RuntimeException(sprintf('Google answered %s with something other than a json object.', $path));
        }

        return $payload;
    }

    private function accountId(): ?string
    {
        $value = $this->configService->get('social-google-business-account-id');

        return is_string($value) && '' !== $value ? $value : null;
    }

    private function locationId(): ?string
    {
        $value = $this->configService->get('social-google-business-location-id');

        return is_string($value) && '' !== $value ? $value : null;
    }
}
