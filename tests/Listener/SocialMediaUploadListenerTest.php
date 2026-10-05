<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Listener;

use c975L\SocialBundle\Entity\SocialMedia;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Listener\SocialMediaUploadListener;
use c975L\SocialBundle\Namer\SocialMediaNamer;
use c975L\SocialBundle\Service\SocialMediaFile;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Event\Event;
use Vich\UploaderBundle\Mapping\PropertyMapping;

#[RequiresPhpExtension('gd')]
class SocialMediaUploadListenerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/social-media-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/public/medias', 0o775, true);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir . '/public/medias/*') ?: []);
        rmdir($this->dir . '/public/medias');
        rmdir($this->dir . '/public');
        rmdir($this->dir);
    }

    private function post(): SocialPost
    {
        return new SocialPost(SocialPost::SOURCE_MANUAL, 'abc', 'Hello', '', null, new \DateTimeImmutable());
    }

    private function event(SocialMedia $media): Event
    {
        return new Event($media, new PropertyMapping('file', 'filename'));
    }

    // A wide transparent PNG becomes a JPEG no larger than SocialMediaFile::MAX_SIDE, its transparency laid on white, then measured once stored
    public function testAPictureBecomesADownscaledJpeg(): void
    {
        $path = $this->dir . '/public/medias/upload.png';
        $image = imagecreatetruecolor(3000, 1000);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagepng($image, $path);

        $media = new SocialMedia($this->post());
        $media->setFile(new File($path));
        $listener = new SocialMediaUploadListener(new SocialMediaFile(), $this->dir);
        $listener->onPreUpload($this->event($media));

        $this->assertSame([2048, 683, \IMAGETYPE_JPEG], \array_slice((array) getimagesize($path), 0, 3));
        $this->assertSame([255, 255, 255], array_values(\array_slice((array) imagecolorsforindex($jpeg = imagecreatefromjpeg($path), imagecolorat($jpeg, 10, 10)), 0, 3)));

        $media->setFilename('medias/upload.png');
        $listener->onPostUpload($this->event($media));
        $this->assertSame([2048, 683], [$media->getWidth(), $media->getHeight()]);
        $this->assertSame('image/jpeg', $media->getMimeType());
    }

    // A file of the site's own, only referenced, is never deleted with its media; an upload is
    public function testAReferenceIsNeverDeleted(): void
    {
        $listener = new SocialMediaUploadListener(new SocialMediaFile(), $this->dir);

        $reference = $this->event(SocialMedia::reference($this->post(), 'medias/gallery.webp', 'image/webp'));
        $listener->onPreRemove($reference);
        $this->assertTrue($reference->isCanceled());

        $upload = $this->event(new SocialMedia($this->post()));
        $listener->onPreRemove($upload);
        $this->assertFalse($upload->isCanceled());
    }

    // An upload over a referenced file leaves that file alone, the media becoming an upload of its own once stored
    public function testAnUploadOverAReferenceSparesItsFile(): void
    {
        $listener = new SocialMediaUploadListener(new SocialMediaFile(), $this->dir);
        $media = SocialMedia::reference($this->post(), 'medias/gallery.mp4', 'video/mp4');
        $media->setFile(new File($this->dir . '/public/medias/clip.mp4', false));

        $listener->onPreRemove($event = $this->event($media));
        $this->assertTrue($event->isCanceled());

        $media->setFilename('medias/clip.mp4');
        $listener->onPostUpload($this->event($media));
        $this->assertFalse($media->isReference());
    }

    // A picture is named a JPEG under the posts' folder, a video keeps its own extension
    public function testTheNamerNamesAJpegOrTheVideosOwnExtension(): void
    {
        $path = $this->dir . '/public/medias/clip';
        file_put_contents($path, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom");
        $media = new SocialMedia($this->post());
        $media->setFile(new File($path));

        $name = new SocialMediaNamer()->name($media, new PropertyMapping('file', 'filename'));

        $this->assertMatchesRegularExpression('#^' . SocialMediaNamer::DIRECTORY . '/[0-9a-f]{32}\.mp4$#', $name);
    }
}
