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
use c975L\SocialBundle\Service\LinkedInClient;
use c975L\SocialBundle\Service\LinkedInPublisher;
use c975L\SocialBundle\Service\SocialImageExporter;
use c975L\UiBundle\Model\SocialContent;
use PHPUnit\Framework\TestCase;

class LinkedInPublisherTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $posted = null;

    private function publisher(?string $image, bool $uploadFails = false): LinkedInPublisher
    {
        $client = $this->createStub(LinkedInClient::class);
        $client->method('author')->willReturn('urn:li:person:abc');
        $client->method('uploadImage')->willReturnCallback(static fn (): string => $uploadFails ? throw new \RuntimeException('Refused') : 'urn:li:image:1');
        $client->method('createPost')->willReturnCallback(function (array $post): string {
            $this->posted = $post;

            return 'urn:li:share:1';
        });

        $exporter = $this->createStub(SocialImageExporter::class);
        $exporter->method('bytes')->willReturn($image);

        return new LinkedInPublisher($client, $exporter, $this->createStub(ConfigServiceInterface::class));
    }

    private function content(): SocialContent
    {
        return new SocialContent('42', 'SocialBundle 2.10', 'https://bundles.975l.com/social-bundle');
    }

    // An article card under the member's name, its image uploaded first, LinkedIn fetching none itself
    public function testThePostIsAnArticleCardWithItsImage(): void
    {
        $this->assertSame('urn:li:share:1', $this->publisher('bytes')->publish('New release', $this->content()));

        $this->assertSame('urn:li:person:abc', $this->posted['author']);
        $this->assertSame(['source' => 'https://bundles.975l.com/social-bundle', 'title' => 'SocialBundle 2.10', 'thumbnail' => 'urn:li:image:1'], $this->posted['content']['article']);
    }

    // An image refused never keeps the text from going out
    public function testAnImageRefusedLeavesTheCardWithoutIt(): void
    {
        $this->publisher('bytes', true)->publish('New release', $this->content());

        $this->assertArrayNotHasKey('thumbnail', $this->posted['content']['article']);
    }

    // LinkedIn's markup characters are escaped, a hashtag kept as the link LinkedIn makes of it
    public function testTheCommentaryIsEscapedAndItsHashtagsLinked(): void
    {
        $this->publisher(null)->publish('Symfony (8) #open_source @975L', $this->content());

        $this->assertSame('Symfony \(8\) {hashtag|\#|open\_source} \@975L', $this->posted['commentary']);
    }
}
