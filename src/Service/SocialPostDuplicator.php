<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\SocialBundle\Entity\SocialMedia;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Namer\SocialMediaNamer;

// A post written again a day later, a draft of no series holding the same content, texts and medias - on the networks still connected only. An upload gets a file of its own, deleted with its own media; a file of the site's own stays a reference to it
class SocialPostDuplicator
{
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    // The copy, not persisted - $networks the ones the site is connected to. Planned at the same time the day after, or tomorrow at that time when that day is already gone
    /** @param list<string> $networks */
    public function duplicate(SocialPost $post, array $networks): SocialPost
    {
        $plannedAt = $post->getPlannedAt()->modify('+1 day');
        if ($plannedAt <= new \DateTimeImmutable()) {
            $plannedAt = new \DateTimeImmutable('tomorrow')->setTime((int) $plannedAt->format('G'), (int) $plannedAt->format('i'));
        }

        $copy = new SocialPost($post->getSourceType(), $post->getSourceId(), $post->getTitle(), $post->getUrl(), $post->getImageUrl(), $plannedAt);
        $copy->setText($post->getText());

        foreach ($post->getTargets() as $target) {
            if (\in_array($target->getNetwork(), $networks, true)) {
                new SocialPostTarget($copy, $target->getNetwork(), $target->getText())->setComment($target->getComment());
            }
        }

        foreach ($post->getMedias() as $media) {
            $copied = $this->copyMedia($media, $copy);
            if (null !== $copied) {
                $copy->addMedia($copied);
            }
        }

        return $copy;
    }

    // The media for the copy, null when its upload is no longer on disk (purged once the post went out everywhere)
    private function copyMedia(SocialMedia $media, SocialPost $copy): ?SocialMedia
    {
        $filename = (string) $media->getFilename();
        if ($media->isReference()) {
            $copied = SocialMedia::reference($copy, $filename, (string) $media->getMimeType(), $media->getWidth(), $media->getHeight(), $media->getDuration());
            $copied->setSource($media->getSourceType(), $media->getSourceId());
        } else {
            $source = $this->projectDir . '/public/' . $filename;
            $target = SocialMediaNamer::DIRECTORY . '/' . bin2hex(random_bytes(16)) . '.' . pathinfo($filename, \PATHINFO_EXTENSION);
            if ('' === $filename || !is_file($source) || !copy($source, $this->projectDir . '/public/' . $target)) {
                return null;
            }

            $copied = new SocialMedia($copy);
            $copied->setFilename($target);
            $copied->setMimeType($media->getMimeType());
            $copied->setSize($media->getSize());
            $copied->setDimensions($media->getWidth(), $media->getHeight(), $media->getDuration());
        }

        $copied->setAlt($media->getAlt());
        $copied->setPosition($media->getPosition());

        return $copied;
    }
}
