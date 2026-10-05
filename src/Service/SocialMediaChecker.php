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
use c975L\SocialBundle\Model\MediaRules;
use Symfony\Component\Translation\TranslatableMessage;

use function Symfony\Component\Translation\t;

// Whether a post's pictures and videos suit each network it goes to, read off that network's own rules (see NetworkPublisherInterface::getMediaRules()): said when the post is saved, and refusing its approval. A picture is not checked for its type or its shape, every network getting it as it takes it (a JPEG, framed for Instagram, see SocialImageExporter); a limit a network does not document is not checked either
class SocialMediaChecker
{
    public function __construct(
        private readonly SocialPublisher $socialPublisher,
    ) {
    }

    // What does not suit, by network, among the networks the post still goes out on
    /** @return array<string, list<TranslatableMessage>> */
    public function check(SocialPost $post): array
    {
        $problems = [];
        foreach ($post->getTargets() as $target) {
            $rules = $target->isPending() ? $this->socialPublisher->getMediaRules($target->getNetwork()) : null;
            $found = null === $rules ? [] : $this->problems($post, $rules);
            if ([] !== $found) {
                $problems[$target->getNetwork()] = $found;
            }
        }

        return $problems;
    }

    /** @return list<TranslatableMessage> */
    private function problems(SocialPost $post, MediaRules $rules): array
    {
        $medias = $post->getMedias()->toArray();
        $videos = array_values(array_filter($medias, static fn (SocialMedia $media): bool => $media->isVideo()));
        $images = \count($medias) - \count($videos);

        // A post prepared from a content goes out with that content's picture when it has none of its own
        $problems = [];
        if ($rules->required && [] === $medias && null === $post->getImageUrl() && $post->isManual()) {
            $problems[] = t('label.social_media_required', [], 'social');
        }
        if ($images > $rules->maxImages) {
            $problems[] = t('label.social_media_too_many', ['%max%' => $rules->maxImages], 'social');
        }
        if ([] !== $videos && !$rules->video) {
            $problems[] = t('label.social_media_no_video', [], 'social');
        }
        if (\count($videos) > 1) {
            $problems[] = t('label.social_media_one_video', [], 'social');
        }
        if ([] !== $videos && $images > 0 && !$rules->mix) {
            $problems[] = t('label.social_media_no_mix', [], 'social');
        }

        foreach ($medias as $media) {
            array_push($problems, ...($media->isVideo() ? $this->videoProblems($media, $rules) : $this->imageProblems($media, $rules)));
        }

        return $problems;
    }

    /** @return list<TranslatableMessage> */
    private function imageProblems(SocialMedia $media, MediaRules $rules): array
    {
        return null !== $rules->maxImageBytes && (int) $media->getSize() > $rules->maxImageBytes
            ? [t('label.social_media_image_too_big', ['%max%' => $this->megabytes($rules->maxImageBytes)], 'social')]
            : [];
    }

    // A duration ffprobe could not read is not checked: the network says it, if it has to
    /** @return list<TranslatableMessage> */
    private function videoProblems(SocialMedia $media, MediaRules $rules): array
    {
        $problems = [];
        if (!\in_array($media->getMimeType(), $rules->videoTypes, true)) {
            $problems[] = t('label.social_media_video_type', ['%types%' => implode(', ', $rules->videoTypes)], 'social');
        }
        if (null !== $rules->maxVideoBytes && (int) $media->getSize() > $rules->maxVideoBytes) {
            $problems[] = t('label.social_media_video_too_big', ['%max%' => $this->megabytes($rules->maxVideoBytes)], 'social');
        }

        $duration = $media->getDuration();
        if (null !== $duration && null !== $rules->minDuration && $duration < $rules->minDuration) {
            $problems[] = t('label.social_media_video_too_short', ['%min%' => $rules->minDuration], 'social');
        }
        if (null !== $duration && null !== $rules->maxDuration && $duration > $rules->maxDuration) {
            $problems[] = t('label.social_media_video_too_long', ['%max%' => (int) round($rules->maxDuration / 60)], 'social');
        }

        return $problems;
    }

    private function megabytes(int $bytes): int
    {
        return (int) round($bytes / 1000000);
    }
}
