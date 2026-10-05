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
use c975L\SocialBundle\Service\LinkedInClient;
use c975L\SocialBundle\Service\LinkedInPublisher;
use c975L\SocialBundle\Service\SocialImageExporter;
use c975L\UiBundle\Model\SocialContent;
use PHPUnit\Framework\TestCase;

class LinkedInPublisherTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $posted = null;

    private string $picture;

    protected function setUp(): void
    {
        $this->picture = (string) tempnam(sys_get_temp_dir(), 'linkedin');
        file_put_contents($this->picture, 'jpeg-bytes');
    }

    protected function tearDown(): void
    {
        @unlink($this->picture);
    }

    private function publisher(?string $image, bool $uploadFails = false): LinkedInPublisher
    {
        $client = $this->createStub(LinkedInClient::class);
        $client->method('author')->willReturn('urn:li:person:abc');
        $uploads = 0;
        $client->method('uploadImage')->willReturnCallback(static function () use ($uploadFails, &$uploads): string {
            return $uploadFails ? throw new \RuntimeException('Refused') : 'urn:li:image:' . ++$uploads;
        });
        $client->method('uploadVideo')->willReturn('urn:li:video:1');
        $client->method('createPost')->willReturnCallback(function (array $post): string {
            $this->posted = $post;

            return 'urn:li:share:1';
        });

        $exporter = $this->createStub(SocialImageExporter::class);
        $exporter->method('bytes')->willReturn($image);

        return new LinkedInPublisher($client, $exporter);
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

    // A post written with no link shows its image alone, or its text alone - an article card needs a source
    public function testAPostWithNoLinkIsItsImageOrItsTextAlone(): void
    {
        $this->publisher('bytes')->publish('Hello', new SocialContent('42', 'Hello', ''));
        $this->assertSame(['media' => ['id' => 'urn:li:image:1']], $this->posted['content']);

        $this->publisher(null)->publish('Hello', new SocialContent('42', 'Hello', ''));
        $this->assertArrayNotHasKey('content', $this->posted);
    }

    // LinkedIn's markup characters are escaped, a hashtag kept as the link LinkedIn makes of it
    public function testTheCommentaryIsEscapedAndItsHashtagsLinked(): void
    {
        $this->publisher(null)->publish('Symfony (8) #open_source @975L', $this->content());

        $this->assertSame('Symfony \(8\) {hashtag|\#|open\_source} \@975L', $this->posted['commentary']);
    }

    // A picture of the post's own replaces the content's image as the card's thumbnail when there is a link
    public function testOnePictureOfThePostIsTheCardThumbnail(): void
    {
        $this->publisher('bytes')->publish('New release', $this->content(), [$this->media('image/jpeg', 'A screen')]);

        $this->assertSame('urn:li:image:1', $this->posted['content']['article']['thumbnail']);
    }

    // With no link, the picture goes alone with its alternative text
    public function testOnePictureWithNoLinkIsAMedia(): void
    {
        $this->publisher(null)->publish('Hello', new SocialContent('42', 'Hello', ''), [$this->media('image/jpeg', 'A screen')]);

        $this->assertSame(['media' => ['id' => 'urn:li:image:1', 'altText' => 'A screen']], $this->posted['content']);
    }

    // Several pictures make a multi-image post in their order, the article dropped
    public function testSeveralPicturesAreAMultiImagePost(): void
    {
        $this->publisher('bytes')->publish('New release', $this->content(), [$this->media('image/jpeg', 'First'), $this->media('image/jpeg')]);

        $this->assertSame(['multiImage' => ['images' => [['id' => 'urn:li:image:1', 'altText' => 'First'], ['id' => 'urn:li:image:2']]]], $this->posted['content']);
    }

    // A video goes as itself, even with a link
    public function testAVideoIsAMedia(): void
    {
        $this->publisher('bytes')->publish('New release', $this->content(), [$this->media('video/mp4')]);

        $this->assertSame(['media' => ['id' => 'urn:li:video:1']], $this->posted['content']);
    }

    // The preview uploads nothing, its placeholders standing for the urns
    public function testThePreviewOfMediasUploadsNothing(): void
    {
        $preview = $this->publisher(null)->preview('Hello', $this->content(), [$this->media('image/jpeg'), $this->media('image/jpeg')]);

        $this->assertSame([['id' => 'urn:li:image:(uploaded first)'], ['id' => 'urn:li:image:(uploaded first)']], $preview['content']['multiImage']['images']);
        $this->assertNull($this->posted);
    }

    // What the Images, MultiImage and Videos APIs document
    public function testTheMediaRulesAreLinkedIns(): void
    {
        $rules = $this->publisher(null)->getMediaRules();

        $this->assertSame([20, true, false, false], [$rules->maxImages, $rules->video, $rules->mix, $rules->required]);
        $this->assertSame(['image/jpeg', 'image/png', 'image/gif'], $rules->imageTypes);
        $this->assertSame([['video/mp4'], 500000000, 3.0, 1800.0], [$rules->videoTypes, $rules->maxVideoBytes, $rules->minDuration, $rules->maxDuration]);
    }

    private function media(string $mimeType, ?string $alt = null): PostMedia
    {
        return new PostMedia($this->picture, 'https://example.org/media', $mimeType, alt: $alt);
    }
}
