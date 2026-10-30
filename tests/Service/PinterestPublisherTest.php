<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Model\PostMedia;
use c975L\SocialBundle\Service\PinterestClient;
use c975L\SocialBundle\Service\PinterestPublisher;
use c975L\SocialBundle\Service\SocialImageExporter;
use c975L\UiBundle\Model\SocialContent;
use PHPUnit\Framework\TestCase;

class PinterestPublisherTest extends TestCase
{
    /** @var list<SocialContent> */
    private array $exported = [];

    private function publisher(?string $jpegUrl = 'https://example.org/medias/social/pin.jpg', ?PinterestClient $client = null): PinterestPublisher
    {
        $exporter = $this->createStub(SocialImageExporter::class);
        $exporter->method('jpegUrl')->willReturnCallback(function (SocialContent $content) use ($jpegUrl): ?string {
            $this->exported[] = $content;

            return $jpegUrl;
        });

        return new PinterestPublisher($client ?? $this->createStub(PinterestClient::class), $exporter);
    }

    private function content(): SocialContent
    {
        return new SocialContent('1', 'La Lune et les Étoiles', 'https://example.org/histoire/la-lune', imagePath: '/tmp/cover.webp', imageAlt: 'La couverture');
    }

    // The text's first line is the title, the lines after the description, the content's address the link - the source picture in a preview, no JPEG written
    public function testThePinTakesTheTitleTheDescriptionTheLinkAndThePicture(): void
    {
        $pin = $this->publisher()->preview("Une histoire du soir\nLa Lune croit tout savoir.\nUne histoire douce.", $this->content());

        $this->assertSame([
            'title' => 'Une histoire du soir',
            'description' => "La Lune croit tout savoir.\nUne histoire douce.",
            'link' => 'https://example.org/histoire/la-lune',
            'alt_text' => 'La couverture',
            'media_source' => ['source_type' => 'image_url', 'url' => '/tmp/cover.webp'],
        ], $pin);
        $this->assertSame([], $this->exported);
    }

    // A single line too long a title is the description as well, a short one the title alone, the title the content's when the text is empty
    public function testASingleLineOrAnEmptyTextStillMakesAPin(): void
    {
        $long = str_repeat('a', 150);
        $single = $this->publisher()->preview($long, $this->content());
        $this->assertSame([str_repeat('a', 100), $long], [$single['title'], $single['description']]);

        $this->assertArrayNotHasKey('description', $this->publisher()->preview('Une histoire du soir', $this->content()));

        $this->assertSame('La Lune et les Étoiles', $this->publisher()->preview('', $this->content())['title']);
    }

    // The post's own picture is pinned rather than the content's, with its own alternative text
    public function testThePostsOwnPictureComesFirst(): void
    {
        $pin = $this->publisher()->preview('Titre', $this->content(), [new PostMedia('/tmp/own.jpg', 'https://example.org/own.jpg', 'image/jpeg', alt: 'Ma photo')]);

        $this->assertSame('/tmp/own.jpg', $pin['media_source']['url']);
        $this->assertSame('Ma photo', $pin['alt_text']);
    }

    // No picture, no pin: said rather than sent to fail
    public function testAContentWithoutPictureIsRefused(): void
    {
        $this->expectExceptionMessage('Pinterest only takes pins with an image, and this content has none.');

        $this->publisher(null)->publish('Titre', new SocialContent('1', 'Titre', 'https://example.org'));
    }

    // Publishing hands the pin to the client and answers its id - one picture, JPEG or PNG, required
    public function testPublishingAnswersThePinsId(): void
    {
        $client = $this->createMock(PinterestClient::class);
        $client->expects($this->once())->method('createPin')->with($this->callback(static fn (array $pin): bool => 'Titre' === $pin['title'] && 'https://example.org/medias/social/pin.jpg' === $pin['media_source']['url']))->willReturn('987');
        $publisher = $this->publisher(client: $client);

        $this->assertSame('987', $publisher->publish('Titre', $this->content()));
        $this->assertSame(['pinterest', 800, 1, false, true], [$publisher->getName(), $publisher->getMaxLength(), $publisher->getMediaRules()->maxImages, $publisher->getMediaRules()->video, $publisher->getMediaRules()->required]);
    }

    // Connected and a board picked, nothing less
    public function testConfiguredOnceABoardIsPicked(): void
    {
        $client = $this->createStub(PinterestClient::class);
        $client->method('isConnected')->willReturn(true);
        $client->method('hasBoard')->willReturn(false);

        $this->assertFalse($this->publisher(client: $client)->isConfigured());
    }
}
