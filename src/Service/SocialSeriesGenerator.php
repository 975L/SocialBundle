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
use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Repository\SocialSeriesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\File;

// A series of drafts, one per moment from a start at a steady pace - its texts one generic text, the site's AI writing each from one instruction, or the next contents of the sources. The series is kept with its settings (see SocialSeries): its drafts are read, corrected and approved one by one, or together from the list, and it is prolonged from its last post with the same settings
class SocialSeriesGenerator
{
    public const string MODE_TEXT = 'text';

    public const string MODE_AI = 'ai';

    public const string MODE_SOURCE = 'source';

    // Where a series' media is written, under public/
    public const string DIRECTORY = 'medias/social/series';

    // The paces: every N days (1 every day, 2 every other day, 7 every week), some days of the week, or every month
    public const array FREQUENCIES = ['days', 'weekdays', 'month'];

    // A series is generated again once it has gone out: neither a pace nor a gap longer than a month
    public const int MAX_INTERVAL = 31;

    public function __construct(
        private readonly SocialPublisher $socialPublisher,
        private readonly SocialPostWriter $writer,
        private readonly SocialMediaFile $mediaFile,
        private readonly EntityManagerInterface $entityManager,
        private readonly SocialSeriesRepository $seriesRepository,
        private readonly string $projectDir,
    ) {
    }

    // The moments of the series, the first at $start - or, for some days of the week (ISO numbers, 1 for Monday), the first of those days from $start, at its time. Every month keeps the day of $start, the last of a month too short for it
    /**
     * @param list<int> $weekdays
     *
     * @return list<\DateTimeImmutable>
     */
    public function moments(\DateTimeImmutable $start, int $count, string $frequency, int $interval = 1, array $weekdays = []): array
    {
        $moments = [];
        $moment = $start;
        while (\count($moments) < $count) {
            if ('weekdays' === $frequency && [] !== $weekdays) {
                if (\in_array((int) $moment->format('N'), $weekdays, true)) {
                    $moments[] = $moment;
                }
                $moment = $moment->modify('+1 day');
                continue;
            }

            // Counted from $start, never from the moment before: 31 January, 28 February, then 31 March again rather than 3 March
            if ('month' === $frequency) {
                $moments[] = $this->monthsAfter($start, \count($moments));
                continue;
            }

            $moments[] = $moment;
            $moment = $moment->modify(sprintf('+%d days', max(1, min(self::MAX_INTERVAL, $interval))));
        }

        return $moments;
    }

    // $months after $start on its day and at its time, the last day of a month too short for it
    private function monthsAfter(\DateTimeImmutable $start, int $months): \DateTimeImmutable
    {
        $month = $start->modify('first day of this month')->modify(sprintf('+%d months', $months));

        return $month->setDate((int) $month->format('Y'), (int) $month->format('n'), min((int) $start->format('j'), (int) $month->format('t')));
    }

    // The series saved and its drafts made from $start - a media given stored once, for every draft and every prolongation to reference
    /** @return list<SocialPost> */
    public function generate(SocialSeries $series, \DateTimeImmutable $start, ?File $media = null): array
    {
        if (null !== $media) {
            $stored = $this->store($media);
            $series->setMedia([...$stored, (int) filesize($this->projectDir . '/public/' . $stored[0])]);
        }
        $this->entityManager->persist($series);

        $posts = $this->make($series, $this->moments($start, $series->getCount(), $series->getFrequency(), $series->getInterval(), $series->getWeekdays()));

        // No draft made keeps nothing: neither an empty series, nor its media
        if ([] === $posts) {
            $this->entityManager->remove($series);
            $this->entityManager->flush();
            if (isset($stored) && is_file($this->projectDir . '/public/' . $stored[0])) {
                unlink($this->projectDir . '/public/' . $stored[0]);
            }
        }

        return $posts;
    }

    // As many drafts again, from the moment after the series' last post, at its pace - none for a series whose posts were all deleted. Its ending is announced again once this new end comes near
    /** @return list<SocialPost> */
    public function prolong(SocialSeries $series): array
    {
        $last = $this->seriesRepository->findLastPlannedAt($series);
        if (null === $last) {
            return [];
        }

        $start = match ($series->getFrequency()) {
            'month' => $last->modify('+1 month'),
            'weekdays' => $last->modify('+1 day'),
            default => $last->modify(sprintf('+%d days', max(1, $series->getInterval()))),
        };

        return $this->make($series, $this->moments($start, $series->getCount(), $series->getFrequency(), $series->getInterval(), $series->getWeekdays()));
    }

    // The drafts of the series at its moments, on its networks - fewer than asked when the AI answers fewer texts or the sources run out. With sources picked, each draft written from a text takes the picture of their next content, reserved as a draft of its own is; the series' media goes with every draft instead
    /**
     * @param list<\DateTimeImmutable> $moments
     *
     * @return list<SocialPost>
     */
    private function make(SocialSeries $series, array $moments): array
    {
        $networks = $series->getNetworks();
        $sources = $series->getSources();

        if (self::MODE_SOURCE === $series->getMode()) {
            $posts = $this->socialPublisher->prepareDrafts($moments, $sources);
            foreach ($posts as $post) {
                $post->setNetworks($networks);
                $post->setSeries($series);
            }
            $this->entityManager->flush();

            return $posts;
        }

        $texts = self::MODE_AI === $series->getMode() ? $this->writer->variants($series->getText(), \count($moments), $this->shortestLength($networks)) : array_fill(0, \count($moments), $series->getText());
        $media = $series->getMedia();

        $posts = [];
        foreach (array_slice($moments, 0, \count($texts)) as $i => $moment) {
            $post = [] === $sources ? $this->socialPublisher->createManual($moment) : $this->socialPublisher->createFromSources($moment, $sources);
            if (null === $post) {
                break;
            }
            $post->setSeries($series);
            $post->setNetworks($networks);
            $post->setText($texts[$i]);
            $this->socialPublisher->rewriteTargets($post);
            $this->socialPublisher->addTargets($post, $post->takeAddedNetworks());
            if (null !== $media) {
                $post->addMedia(SocialMedia::reference($post, $media[0], $media[1], $media[2], $media[3], $media[4])->setSize($media[5]));
            }
            // Saved one by one with sources picked, so the next draft's content is picked among the ones not taken yet
            $this->entityManager->persist($post);
            if ([] !== $sources) {
                $this->entityManager->flush();
            }
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
