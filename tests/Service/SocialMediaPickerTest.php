<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Namer\SocialMediaNamer;
use c975L\SocialBundle\Service\SocialMediaFile;
use c975L\SocialBundle\Service\SocialMediaPicker;
use c975L\UiBundle\Contract\PickableMediaProviderInterface;
use c975L\UiBundle\Model\PickableMedia;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function Symfony\Component\Translation\t;

class SocialMediaPickerTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/social-picker-' . bin2hex(random_bytes(4));
        mkdir($this->projectDir . '/public/medias/gallery', 0o755, true);
        $image = imagecreatetruecolor(40, 30);
        imagewebp($image, $this->projectDir . '/public/medias/gallery/lac.webp');
        file_put_contents($this->projectDir . '/public/medias/gallery/cascade.mp4', 'not really a video');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->projectDir);
    }

    private function createPicker(PickableMedia ...$medias): SocialMediaPicker
    {
        $provider = $this->createStub(PickableMediaProviderInterface::class);
        $provider->method('getPickableMediaLabel')->willReturn(t('label.gallery', [], 'gallery'));
        $provider->method('findPickableMedia')->willReturn($medias);

        return new SocialMediaPicker([$provider], new SocialMediaFile(), $this->projectDir);
    }

    private function createPost(): SocialPost
    {
        return new SocialPost(SocialPost::SOURCE_MANUAL, 'abc', 'Hello', '', null, new \DateTimeImmutable('+1 day'));
    }

    // A WebP of the gallery becomes the post's own JPEG, measured, purged with it
    public function testAPictureIsCopiedAsThePostsOwnJpeg(): void
    {
        $post = $this->createPost();

        $added = $this->createPicker(new PickableMedia('medias/gallery/lac.webp', 'Lac', 'image/webp'))->attach($post, '', ['medias/gallery/lac.webp']);

        $media = $post->getMedias()->first();
        $this->assertSame(1, $added);
        $this->assertFalse($media->isReference());
        $this->assertStringStartsWith(SocialMediaNamer::DIRECTORY . '/', (string) $media->getFilename());
        $this->assertStringEndsWith('.jpg', (string) $media->getFilename());
        $this->assertSame('image/jpeg', $media->getMimeType());
        $this->assertSame([40, 30], [$media->getWidth(), $media->getHeight()]);
        $this->assertSame('Lac', $media->getAlt());
        $this->assertSame('image/jpeg', mime_content_type($this->projectDir . '/public/' . $media->getFilename()));
    }

    // Too heavy to copy, a video stays the site's file, never purged
    public function testAVideoIsOnlyReferenced(): void
    {
        $post = $this->createPost();

        $this->createPicker(new PickableMedia('medias/gallery/cascade.mp4', 'Cascade', 'video/mp4'))->attach($post, '', ['medias/gallery/cascade.mp4']);

        $media = $post->getMedias()->first();
        $this->assertTrue($media->isReference());
        $this->assertSame('medias/gallery/cascade.mp4', $media->getFilename());
        $this->assertSame('video/mp4', $media->getMimeType());
        $this->assertSame(18, $media->getSize());
    }

    // A path the libraries did not offer is no file of the site to take, nor one gone from the disk
    public function testOnlyTheOfferedFilesStillOnDiskAreAdded(): void
    {
        $post = $this->createPost();
        $picker = $this->createPicker(new PickableMedia('medias/gallery/lac.webp', 'Lac', 'image/webp'), new PickableMedia('medias/gallery/gone.webp', 'Gone', 'image/webp'));

        $added = $picker->attach($post, '', ['../../config/secrets.php', 'medias/gallery/gone.webp', 'medias/gallery/lac.webp', 'medias/gallery/lac.webp']);

        $this->assertSame(1, $added);
        $this->assertCount(1, $post->getMedias());
    }

    // Added after the post's own medias, in the order picked
    public function testThePickedMediasFollowThePostsOwn(): void
    {
        $post = $this->createPost();
        $picker = $this->createPicker(new PickableMedia('medias/gallery/lac.webp', 'Lac', 'image/webp'), new PickableMedia('medias/gallery/cascade.mp4', 'Cascade', 'video/mp4'));

        $picker->attach($post, '', ['medias/gallery/lac.webp', 'medias/gallery/cascade.mp4']);

        $this->assertSame([0, 1], $post->getMedias()->map(static fn ($media): int => $media->getPosition())->getValues());
    }

    public function testTheLibrariesAreListedWithTheirLabel(): void
    {
        $libraries = $this->createPicker(new PickableMedia('medias/gallery/lac.webp', 'Lac', 'image/webp'))->libraries('lac');

        $this->assertCount(1, $libraries);
        $this->assertCount(1, $libraries[0]['medias']);
    }

    public function testWithoutAProviderThereIsNoLibrary(): void
    {
        $this->assertFalse(new SocialMediaPicker([], new SocialMediaFile(), $this->projectDir)->hasLibraries());
        $this->assertTrue($this->createPicker()->hasLibraries());
    }
}
