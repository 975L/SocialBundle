<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Namer;

use c975L\SocialBundle\Entity\SocialMedia;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\PropertyMappingInterface;
use Vich\UploaderBundle\Naming\NamerInterface;

// A media uploaded for a post, stored under public/medias/social/ by a random name: a picture always as a JPEG, the one format every network takes (see SocialMediaUploadListener), a video with its own extension
/** @implements NamerInterface<SocialMedia> */
class SocialMediaNamer implements NamerInterface
{
    public const string DIRECTORY = 'medias/social/posts';

    public function name(object | array $object, PropertyMappingInterface $mapping): string
    {
        $file = $mapping->getFile($object);
        if (!$object instanceof SocialMedia || !$file instanceof File) {
            throw new \InvalidArgumentException('SocialMediaNamer only names the file of a SocialMedia.');
        }

        return self::DIRECTORY . '/' . bin2hex(random_bytes(16)) . '.' . $this->extension($file);
    }

    // A temporary upload has no extension of its own: its type is read off its content
    private function extension(File $file): string
    {
        $mimeType = (string) $file->getMimeType();
        if (\in_array($mimeType, SocialMedia::VIDEO_TYPES, true)) {
            return 'video/quicktime' === $mimeType ? 'mov' : 'mp4';
        }

        return 'jpg';
    }
}
