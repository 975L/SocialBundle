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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\File;

// A series of drafts, one per moment from a start at a steady pace - its texts one generic text, the site's AI writing each from one instruction, or the next contents of the sources. Nothing is stored about the series itself: the drafts are read, corrected and approved one by one, or together from the list, and a new series is generated when this one has gone out
class SocialSeriesGenerator
{
    public const string MODE_TEXT = 'text';

    public const string MODE_AI = 'ai';

    public const string MODE_SOURCE = 'source';

    // Where a series' media is written, under public/
    public const string DIRECTORY = 'medias/social/series';

    // The pace, as DateTimeImmutable::modify() reads it
    public const array FREQUENCIES = ['day' => '+1 day', 'week' => '+1 week', 'month' => '+1 month'];

    public function __construct(
        private readonly SocialPublisher $socialPublisher,
        private readonly SocialPostWriter $writer,
        private readonly SocialMediaFile $mediaFile,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $projectDir,
    ) {
    }

    // The moments of the series, the first at $start
    /** @return list<\DateTimeImmutable> */
    public function moments(\DateTimeImmutable $start, int $count, string $frequency): array
    {
        $moments = [];
        for ($i = 0, $moment = $start; $i < $count; ++$i, $moment = $moment->modify(self::FREQUENCIES[$frequency] ?? self::FREQUENCIES['day'])) {
            $moments[] = $moment;
        }

        return $moments;
    }

    // The drafts made, on the networks given - fewer than asked when the AI answers fewer texts or the sources run out. A media given goes with every draft written here, stored once and only referenced by each
    /**
     * @param list<\DateTimeImmutable> $moments
     * @param list<string>             $networks
     * @param list<string>             $sources
     *
     * @return list<SocialPost>
     */
    public function generate(array $moments, string $mode, array $networks, string $text = '', array $sources = [], ?File $media = null): array
    {
        if (self::MODE_SOURCE === $mode) {
            $posts = $this->socialPublisher->prepareDrafts($moments, $sources);
            foreach ($posts as $post) {
                $post->setNetworks($networks);
            }
            $this->entityManager->flush();

            return $posts;
        }

        $texts = self::MODE_AI === $mode ? $this->writer->variants($text, \count($moments), $this->shortestLength($networks)) : array_fill(0, \count($moments), $text);
        $stored = null === $media ? null : $this->store($media);
        $size = null === $stored ? null : (int) filesize($this->projectDir . '/public/' . $stored[0]);

        $posts = [];
        foreach (array_slice($moments, 0, \count($texts)) as $i => $moment) {
            $post = $this->socialPublisher->createManual($moment);
            $post->setNetworks($networks);
            $post->setText($texts[$i]);
            $this->socialPublisher->rewriteTargets($post);
            $this->socialPublisher->addTargets($post, $post->takeAddedNetworks());
            if (null !== $stored) {
                $post->addMedia(SocialMedia::reference($post, ...$stored)->setSize($size));
            }
            $this->entityManager->persist($post);
            $posts[] = $post;
        }
        $this->entityManager->flush();

        return $posts;
    }

    // The AI writes for the shortest network ticked, so one text suits them all
    /** @param list<string> $networks */
    private function shortestLength(array $networks): int
    {
        $lengths = array_filter(array_map($this->socialPublisher->getMaxLength(...), $networks));

        return [] === $lengths ? 300 : min($lengths);
    }

    // The series' media, written once in a folder of its own - a picture as the same JPEG an upload becomes - and measured. Only referenced by its drafts, it is kept when they are purged
    /** @return array{0: string, 1: string, 2: ?int, 3: ?int, 4: ?float} */
    private function store(File $media): array
    {
        $video = \in_array($media->getMimeType(), SocialMedia::VIDEO_TYPES, true);
        $name = self::DIRECTORY . '/' . bin2hex(random_bytes(16)) . '.' . ($video ? ('video/quicktime' === $media->getMimeType() ? 'mov' : 'mp4') : 'jpg');
        $mimeType = $video ? (string) $media->getMimeType() : 'image/jpeg';
        $moved = $media->move($this->projectDir . '/public/' . \dirname($name), basename($name));
        if (!$video) {
            $this->mediaFile->toJpeg($moved->getPathname());
        }
        [$width, $height, $duration] = $this->mediaFile->measure($moved->getPathname(), $video);

        return [$name, $mimeType, $width, $height, $duration];
    }
}
