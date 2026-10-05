<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Listener;

use c975L\SocialBundle\Entity\SocialMedia;
use c975L\SocialBundle\Service\SocialMediaFile;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Event\Event;
use Vich\UploaderBundle\Event\Events;

// A media uploaded for a post becomes the one file every network takes: a picture turned upright, downscaled and written as a JPEG before Vich stores it, then measured; a video kept as it is, measured by ffprobe where the host has it. A file of the site's own, only referenced, is never deleted from here
class SocialMediaUploadListener
{
    public function __construct(
        private readonly SocialMediaFile $mediaFile,
        private readonly string $projectDir,
    ) {
    }

    // Rewritten in place, so Vich stores, names and measures the JPEG rather than the upload
    #[AsEventListener(event: Events::PRE_UPLOAD)]
    public function onPreUpload(Event $event): void
    {
        $media = $event->getObject();
        $file = $media instanceof SocialMedia ? $media->getFile() : null;
        if (!$file instanceof File || \in_array($file->getMimeType(), SocialMedia::VIDEO_TYPES, true) || false === @getimagesize($file->getPathname())) {
            return;
        }

        $this->mediaFile->toJpeg($file->getPathname());
    }

    // The upload stored, a former reference is now a file of its own - only now, the file it replaced having been spared by onPreRemove
    #[AsEventListener(event: Events::POST_UPLOAD)]
    public function onPostUpload(Event $event): void
    {
        $media = $event->getObject();
        if (!$media instanceof SocialMedia || null === $media->getFilename()) {
            return;
        }

        $media->setReference(false);
        $path = $this->projectDir . '/public/' . $media->getFilename();
        $media->setMimeType($media->isVideo() ? $media->getMimeType() : 'image/jpeg');
        $media->setDimensions(...$this->mediaFile->measure($path, $media->isVideo()));
    }

    // A file only referenced belongs to whoever stored it - a gallery's picture is not deleted with the post that showed it
    #[AsEventListener(event: Events::PRE_REMOVE)]
    public function onPreRemove(Event $event): void
    {
        $media = $event->getObject();
        if ($media instanceof SocialMedia && $media->isReference()) {
            $event->cancel();
        }
    }
}
