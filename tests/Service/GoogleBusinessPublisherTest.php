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
use c975L\ConfigBundle\Service\SiteLocales;
use c975L\SocialBundle\Contract\OnDemandPublisherInterface;
use c975L\SocialBundle\Model\PostMedia;
use c975L\SocialBundle\Service\GoogleBusinessClient;
use c975L\SocialBundle\Service\GoogleBusinessPublisher;
use c975L\SocialBundle\Service\GoogleOAuthClient;
use c975L\SocialBundle\Service\SocialImageExporter;
use c975L\UiBundle\Model\SocialContent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class GoogleBusinessPublisherTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: mixed}> */
    private array $calls = [];

    // The publisher on a listing "123"/"456", Google answering $answer to every request recorded in $calls
    private function publisher(string $answer = '{"name":"accounts/123/locations/456/localPosts/789"}', ?string $locationId = '456'): GoogleBusinessPublisher
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($answer): MockResponse {
            $this->calls[] = ['method' => $method, 'url' => $url, 'body' => json_decode($options['body'] ?? 'null', true)];

            return new MockResponse($answer);
        });
        $oauth = $this->createStub(GoogleOAuthClient::class);
        $oauth->method('isConfigured')->willReturn(true);
        $oauth->method('getAccessToken')->willReturn('access-token');
        $config = $this->createStub(ConfigServiceInterface::class);
        $config->method('get')->willReturnMap([
            ['social-google-business-account-id', '123'],
            ['social-google-business-location-id', $locationId],
        ]);
        $exporter = $this->createStub(SocialImageExporter::class);
        $exporter->method('jpegUrl')->willReturn('https://example.org/medias/social/item-1.jpg');

        return new GoogleBusinessPublisher(new GoogleBusinessClient($httpClient, $oauth, $config), $exporter, new SiteLocales(['fr', 'en'], 'fr'));
    }

    private function content(string $url = 'https://example.org/catalog/item-1', ?string $imagePath = '/tmp/item-1.webp', ?string $locale = 'en'): SocialContent
    {
        return new SocialContent('1', 'Item 1', $url, imagePath: $imagePath, locale: $locale);
    }

    // A standard post: the text as summary, the content's language, the picture, and the address behind "Learn more" - the source picture in a preview, nothing sent
    public function testThePreviewIsAStandardPostWithItsPictureAndButton(): void
    {
        $this->assertSame([
            'languageCode' => 'en',
            'summary' => 'What is new',
            'topicType' => 'STANDARD',
            'callToAction' => ['actionType' => 'LEARN_MORE', 'url' => 'https://example.org/catalog/item-1'],
            'media' => [['mediaFormat' => 'PHOTO', 'sourceUrl' => '/tmp/item-1.webp']],
        ], $this->publisher()->preview(' What is new ', $this->content()));
        $this->assertSame([], $this->calls);
    }

    // A content saying no language goes out in the site's default one, Google filing a post without any as English
    public function testAContentWithoutLanguageTakesTheSiteDefault(): void
    {
        $this->assertSame('fr', $this->publisher()->preview('Text', $this->content(locale: null))['languageCode']);
    }

    // The template's text putting the address last loses it, the button carrying it
    public function testTheAddressIsTakenOutOfTheText(): void
    {
        $this->assertSame('Item 1', $this->publisher()->preview("Item 1\n\nhttps://example.org/catalog/item-1", $this->content())['summary']);
    }

    // No picture and no address: neither media nor button, the summary cut to Google's length
    public function testAPostWithoutPictureNorAddressIsTextAlone(): void
    {
        $post = $this->publisher()->preview(str_repeat('a', 1600), $this->content('', null));

        $this->assertSame(['languageCode', 'summary', 'topicType'], array_keys($post));
        $this->assertSame(1500, mb_strlen($post['summary']));
    }

    // The post's own picture goes out rather than the content's, a video skipped
    public function testThePostsOwnPictureComesFirst(): void
    {
        $medias = [
            new PostMedia('/tmp/clip.mp4', 'https://example.org/clip.mp4', 'video/mp4'),
            new PostMedia('/tmp/own.jpg', 'https://example.org/own.jpg', 'image/jpeg'),
        ];

        $this->assertSame('/tmp/own.jpg', $this->publisher()->preview('Text', $this->content(), $medias)['media'][0]['sourceUrl']);
    }

    // Published on the listing's local posts with the JPEG copy, the post's resource name returned
    public function testPublishPostsOnTheListingAndReturnsItsName(): void
    {
        $name = $this->publisher()->publish('What is new', $this->content());

        $this->assertSame('accounts/123/locations/456/localPosts/789', $name);
        $this->assertSame('POST', $this->calls[0]['method']);
        $this->assertSame('https://mybusiness.googleapis.com/v4/accounts/123/locations/456/localPosts', $this->calls[0]['url']);
        $this->assertSame('https://example.org/medias/social/item-1.jpg', $this->calls[0]['body']['media'][0]['sourceUrl']);
    }

    // A post Google accepted is never marked failed for a name missing from its answer
    public function testAnAcceptedPostWithoutNameIsNotFailed(): void
    {
        $this->assertSame('', $this->publisher('{}')->publish('What is new', $this->content()));
    }

    // Google's refusal is thrown with its own words, so the target is marked failed with them
    public function testARefusalIsThrown(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('{"error":{"message":"invalid argument"}}', ['http_code' => 400]));
        $oauth = $this->createStub(GoogleOAuthClient::class);
        $oauth->method('getAccessToken')->willReturn('access-token');
        $publisher = new GoogleBusinessPublisher(new GoogleBusinessClient($httpClient, $oauth, $this->createStub(ConfigServiceInterface::class)), $this->createStub(SocialImageExporter::class), new SiteLocales(['fr', 'en'], 'fr'));

        $this->expectExceptionMessageIsOrContains('invalid argument');
        $publisher->publish('What is new', $this->content(imagePath: null));
    }

    // Ticked by hand only, and connected once a listing is resolved
    public function testItIsTickedByHandAndNeedsAListing(): void
    {
        $this->assertInstanceOf(OnDemandPublisherInterface::class, $this->publisher());
        $this->assertTrue($this->publisher()->isConfigured());
        $this->assertFalse($this->publisher(locationId: null)->isConfigured());
    }
}
