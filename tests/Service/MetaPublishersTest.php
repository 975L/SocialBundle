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
use c975L\SocialBundle\Service\FacebookPublisher;
use c975L\SocialBundle\Service\InstagramPublisher;
use c975L\SocialBundle\Service\MetaGraphClient;
use c975L\SocialBundle\Service\SocialImageExporter;
use c975L\UiBundle\Model\SocialContent;
use PHPUnit\Framework\TestCase;

class MetaPublishersTest extends TestCase
{
    /** @var list<array{method: string, path: string, parameters: array<string, string>}> */
    private array $calls = [];

    /**
     * @param list<array<string, mixed>> $answers
     */
    private function graph(array $answers): MetaGraphClient
    {
        $graph = $this->createStub(MetaGraphClient::class);
        $graph->method('pageToken')->willReturn('page-token');
        $graph->method('request')->willReturnCallback(function (string $method, string $path, array $parameters) use (&$answers): array {
            $this->calls[] = ['method' => $method, 'path' => $path, 'parameters' => $parameters];

            return array_shift($answers) ?? [];
        });

        return $graph;
    }

    private function exporter(?string $jpegUrl): SocialImageExporter
    {
        $exporter = $this->createStub(SocialImageExporter::class);
        $exporter->method('jpegUrl')->willReturn($jpegUrl);

        return $exporter;
    }

    private function config(): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap([
            ['social-meta-page-id', '55'],
            ['social-meta-instagram-id', '77'],
            ['social-facebook-publish-mode', 'auto'],
            ['social-instagram-publish-mode', null],
        ]);

        return $configService;
    }

    private function content(): SocialContent
    {
        return new SocialContent('1', 'Lac', 'https://example.org/lac', imageAlt: 'Le lac au matin');
    }

    public function testFacebookPostsAPhotoWithItsCaptionOnThePage(): void
    {
        $id = new FacebookPublisher($this->graph([['id' => '9', 'post_id' => '55_9']]), $this->exporter('https://example.org/medias/social/a.jpg'), $this->config())->publish('Texte', $this->content());

        $this->assertSame('55_9', $id);
        $this->assertSame('/55/photos', $this->calls[0]['path']);
        $this->assertSame(['url' => 'https://example.org/medias/social/a.jpg', 'caption' => 'Texte', 'access_token' => 'page-token'], $this->calls[0]['parameters']);
    }

    // Without an image, a link: Facebook draws the page's own preview
    public function testFacebookPostsALinkWhenTheContentHasNoImage(): void
    {
        $preview = new FacebookPublisher($this->graph([]), $this->exporter(null), $this->config())->preview('Texte', $this->content());

        $this->assertSame(['path' => '/55/feed', 'parameters' => ['message' => 'Texte', 'link' => 'https://example.org/lac']], $preview);
    }

    public function testEachNetworkReadsItsOwnMode(): void
    {
        $this->assertTrue(new FacebookPublisher($this->graph([]), $this->exporter(null), $this->config())->isAutomatic());
        $this->assertFalse(new InstagramPublisher($this->graph([]), $this->exporter(null), $this->config())->isAutomatic());
    }

    // Created, waited for while Instagram processes the image, then published
    public function testInstagramPublishesTheContainerOnceItIsProcessed(): void
    {
        $graph = $this->graph([['id' => 'c1'], ['status_code' => 'IN_PROGRESS'], ['status_code' => 'FINISHED'], ['id' => 'm1']]);

        $id = new InstagramPublisher($graph, $this->exporter('https://example.org/medias/social/a.jpg'), $this->config(), 0)->publish('Texte', $this->content());

        $this->assertSame('m1', $id);
        $this->assertSame(['image_url' => 'https://example.org/medias/social/a.jpg', 'caption' => 'Texte', 'alt_text' => 'Le lac au matin', 'access_token' => 'page-token'], $this->calls[0]['parameters']);
        $this->assertSame('/77/media_publish', $this->calls[3]['path']);
        $this->assertSame('c1', $this->calls[3]['parameters']['creation_id']);
    }

    public function testInstagramSaysWhenItCouldNotProcessTheImage(): void
    {
        $this->expectExceptionMessage('could not process the image (ERROR)');

        new InstagramPublisher($this->graph([['id' => 'c1'], ['status_code' => 'ERROR']]), $this->exporter('https://example.org/a.jpg'), $this->config(), 0)->publish('Texte', $this->content());
    }

    public function testInstagramTakesNoPostWithoutAnImage(): void
    {
        $this->expectExceptionMessage('only takes posts with an image');

        new InstagramPublisher($this->graph([]), $this->exporter(null), $this->config(), 0)->publish('Texte', $this->content());
    }
}
