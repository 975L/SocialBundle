<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Service\GoogleBusinessLocationResolver;
use c975L\SocialBundle\Service\GoogleOAuthClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class GoogleBusinessLocationResolverTest extends TestCase
{
    private function createResolver(MockHttpClient $httpClient): GoogleBusinessLocationResolver
    {
        $googleOAuthClient = $this->createStub(GoogleOAuthClient::class);
        $googleOAuthClient->method('getAccessToken')->willReturn('access-token');

        return new GoogleBusinessLocationResolver($httpClient, $googleOAuthClient);
    }

    // Google names a resource "accounts/123", where only the id itself is stored - the path being rebuilt where it is needed
    public function testResolveFirstStripsThePrefixOffBothResourceNames(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode(['accounts' => [['name' => 'accounts/123']]], \JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode(['locations' => [['name' => 'locations/456']]], \JSON_THROW_ON_ERROR)),
        ]);

        $listing = $this->createResolver($httpClient)->resolveFirst();

        $this->assertSame(['accountId' => '123', 'locationId' => '456'], $listing);
    }

    // The first listing of the first account is what a site owning one listing has; an owner holding several edits the two configs by hand afterwards
    public function testResolveFirstKeepsTheFirstOfEach(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode(['accounts' => [['name' => 'accounts/123'], ['name' => 'accounts/999']]], \JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode(['locations' => [['name' => 'locations/456'], ['name' => 'locations/789']]], \JSON_THROW_ON_ERROR)),
        ]);

        $listing = $this->createResolver($httpClient)->resolveFirst();

        $this->assertSame(['accountId' => '123', 'locationId' => '456'], $listing);
    }

    public function testResolveFirstThrowsWhenTheAccountManagesNoBusinessProfile(): void
    {
        $httpClient = new MockHttpClient([new MockResponse('{}')]);

        $this->expectException(\RuntimeException::class);

        $this->createResolver($httpClient)->resolveFirst();
    }

    public function testResolveFirstThrowsWhenTheAccountHoldsNoListing(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode(['accounts' => [['name' => 'accounts/123']]], \JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode(['locations' => []], \JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(\RuntimeException::class);

        $this->createResolver($httpClient)->resolveFirst();
    }

    // An entry Google answers with under another shape is stepped over rather than stored as a broken id
    public function testResolveFirstIgnoresResourcesWhoseNameIsMissingOrUnprefixed(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode(['accounts' => [['id' => 'no-name'], ['name' => 'locations/oops'], ['name' => 'accounts/123']]], \JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode(['locations' => [['name' => 'locations/456']]], \JSON_THROW_ON_ERROR)),
        ]);

        $listing = $this->createResolver($httpClient)->resolveFirst();

        $this->assertSame('123', $listing['accountId']);
    }

    public function testResolveFirstThrowsOnAnErrorAnswer(): void
    {
        $httpClient = new MockHttpClient([new MockResponse('{"error":{"message":"forbidden"}}', ['http_code' => 403])]);

        $this->expectException(\RuntimeException::class);

        $this->createResolver($httpClient)->resolveFirst();
    }
}
