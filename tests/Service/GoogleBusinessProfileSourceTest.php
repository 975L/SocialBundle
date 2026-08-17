<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Model\ReviewData;
use c975L\SocialBundle\Service\GoogleBusinessProfileSource;
use c975L\SocialBundle\Service\GoogleOAuthClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class GoogleBusinessProfileSourceTest extends TestCase
{
    private function createConfigService(?string $accountId = '123', ?string $locationId = '456'): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap([
            ['social-google-business-account-id', $accountId],
            ['social-google-business-location-id', $locationId],
        ]);

        return $configService;
    }

    private function createOAuthClient(bool $configured = true): GoogleOAuthClient
    {
        $googleOAuthClient = $this->createStub(GoogleOAuthClient::class);
        $googleOAuthClient->method('isConfigured')->willReturn($configured);
        $googleOAuthClient->method('getAccessToken')->willReturn('access-token');

        return $googleOAuthClient;
    }

    private function createSource(MockHttpClient $httpClient, ?string $accountId = '123'): GoogleBusinessProfileSource
    {
        return new GoogleBusinessProfileSource($httpClient, $this->createOAuthClient(), $this->createConfigService($accountId));
    }

    // starRating is an enum, so a review whose rating is read as a number would be stored at zero
    public function testFetchMapsTheStarRatingEnumToAnInteger(): void
    {
        $httpClient = new MockHttpClient([new MockResponse(json_encode([
            'reviews' => [[
                'reviewId' => 'r1',
                'starRating' => 'FOUR',
                'comment' => 'Impeccable',
                'createTime' => '2026-08-01T10:00:00Z',
                'reviewer' => ['displayName' => 'Jean D.', 'profilePhotoUrl' => 'https://example.org/a.png'],
            ]],
        ], \JSON_THROW_ON_ERROR))]);

        $reviews = iterator_to_array($this->createSource($httpClient)->fetch());

        $this->assertCount(1, $reviews);
        $this->assertInstanceOf(ReviewData::class, $reviews[0]);
        $this->assertSame(4, $reviews[0]->rating);
        $this->assertSame('r1', $reviews[0]->externalId);
        $this->assertSame('Jean D.', $reviews[0]->authorName);
        $this->assertSame('https://example.org/a.png', $reviews[0]->authorAvatarUrl);
    }

    // Naming an unnamed author is the card's call: a label stored here would be frozen in the locale of the import
    public function testFetchLeavesTheAuthorNameNullWhenGoogleServesNone(): void
    {
        $httpClient = new MockHttpClient([new MockResponse(json_encode([
            'reviews' => [['reviewId' => 'r1', 'starRating' => 'FIVE', 'createTime' => '2026-08-01T10:00:00Z']],
        ], \JSON_THROW_ON_ERROR))]);

        $reviews = iterator_to_array($this->createSource($httpClient)->fetch());

        $this->assertNull($reviews[0]->authorName);
    }

    // A rating with no text is a review too, and must not be dropped on its way in
    public function testFetchKeepsARatingOnlyReview(): void
    {
        $httpClient = new MockHttpClient([new MockResponse(json_encode([
            'reviews' => [['reviewId' => 'r1', 'starRating' => 'FIVE', 'createTime' => '2026-08-01T10:00:00Z']],
        ], \JSON_THROW_ON_ERROR))]);

        $reviews = iterator_to_array($this->createSource($httpClient)->fetch());

        $this->assertCount(1, $reviews);
        $this->assertNull($reviews[0]->comment);
    }

    // Something with no id could never be updated on the next run, and something with no readable rating could not be displayed
    public function testFetchSkipsEntriesWithoutAnIdOrAReadableRating(): void
    {
        $httpClient = new MockHttpClient([new MockResponse(json_encode([
            'reviews' => [
                ['starRating' => 'FIVE'],
                ['reviewId' => 'r2', 'starRating' => 'STAR_RATING_UNSPECIFIED'],
                ['reviewId' => 'r3', 'starRating' => 'THREE', 'createTime' => '2026-08-01T10:00:00Z'],
            ],
        ], \JSON_THROW_ON_ERROR))]);

        $reviews = iterator_to_array($this->createSource($httpClient)->fetch());

        $this->assertCount(1, $reviews);
        $this->assertSame('r3', $reviews[0]->externalId);
    }

    // A listing with more reviews than one page holds must be walked through, not truncated at the first answer
    public function testFetchFollowsThePageToken(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode([
                'reviews' => [['reviewId' => 'r1', 'starRating' => 'FIVE', 'createTime' => '2026-08-01T10:00:00Z']],
                'nextPageToken' => 'next',
            ], \JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'reviews' => [['reviewId' => 'r2', 'starRating' => 'ONE', 'createTime' => '2026-08-02T10:00:00Z']],
            ], \JSON_THROW_ON_ERROR)),
        ]);

        $reviews = iterator_to_array($this->createSource($httpClient)->fetch());

        $this->assertSame(['r1', 'r2'], array_map(static fn (ReviewData $review): string => $review->externalId, $reviews));
    }

    public function testFetchThrowsOnAnErrorAnswer(): void
    {
        $httpClient = new MockHttpClient([new MockResponse('{"error":{"message":"forbidden"}}', ['http_code' => 403])]);

        $this->expectException(\RuntimeException::class);

        iterator_to_array($this->createSource($httpClient)->fetch());
    }

    // A site that never connected has nothing to sync, which the synchronizer reads off this rather than off a failed call
    public function testIsConfiguredIsFalseWithoutAListing(): void
    {
        $source = $this->createSource(new MockHttpClient(), null);

        $this->assertFalse($source->isConfigured());
    }

    // An emptied reply removes the answer on the platform, which is a DELETE and not a PUT of an empty string
    public function testReplyDeletesTheAnswerWhenTheCommentIsEmptied(): void
    {
        $methods = [];
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$methods): MockResponse {
            $methods[] = $method;

            return new MockResponse('');
        });

        $this->createSource($httpClient)->reply('r1', '   ');

        $this->assertSame(['DELETE'], $methods);
    }

    public function testReplyPutsTheAnswerWhenThereIsOne(): void
    {
        $methods = [];
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$methods): MockResponse {
            $methods[] = $method;

            return new MockResponse('{}');
        });

        $this->createSource($httpClient)->reply('r1', 'Merci !');

        $this->assertSame(['PUT'], $methods);
    }
}
