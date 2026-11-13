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
use c975L\SocialBundle\Repository\SocialPostRepository;
use Doctrine\ORM\EntityManagerInterface;

// A series deleted: its posts not gone out anywhere cancelled, those gone out kept outside any series as the record of what was sent, its media removed once no post shows it
class SocialSeriesDeleter
{
    public function __construct(
        private readonly SocialPostRepository $postRepository,
        private readonly SocialPublisher $publisher,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $projectDir,
    ) {
    }

    // The series' posts counted as deleting it would treat them: those it cancels, those it keeps
    /** @return array{cancelled: int, kept: int} */
    public function count(SocialSeries $series): array
    {
        $posts = $this->postRepository->findBy(['series' => $series]);
        $kept = \count(array_filter($posts, static fn (SocialPost $post): bool => $post->hasGoneOut()));

        return ['cancelled' => \count($posts) - $kept, 'kept' => $kept];
    }

    // The series deleted, each post cancelled flushed at once - null when one is being sent, the series then kept for a later try
    /** @return ?array{cancelled: int, kept: int} */
    public function delete(SocialSeries $series): ?array
    {
        $cancelled = 0;
        $kept = [];
        foreach ($this->postRepository->findBy(['series' => $series]) as $post) {
            // Refused: gone out meanwhile, kept with the others, or being sent
            if ($post->hasGoneOut() || !$this->publisher->delete($post, true)) {
                if (!$post->hasGoneOut()) {
                    return null;
                }
                $kept[] = $post;

                continue;
            }
            ++$cancelled;
        }

        foreach ($kept as $post) {
            $post->setSeries(null);
        }
        $media = $series->getMedia();
        $this->entityManager->remove($series);
        $this->entityManager->flush();

        // The file a post still shows stays - one kept, or a copy made outside the series
        if (null !== $media && 0 === $this->entityManager->getRepository(SocialMedia::class)->count(['filename' => $media[0]])) {
            $this->removeMedia($media[0]);
        }

        return ['cancelled' => $cancelled, 'kept' => \count($kept)];
    }

    // The series' media file removed, a path outside the series' folder left alone
    private function removeMedia(string $path): void
    {
        $file = $this->projectDir . '/public/' . $path;
        if (str_starts_with($path, SocialSeriesGenerator::DIRECTORY . '/') && !str_contains($path, '..') && is_file($file)) {
            unlink($file);
        }
    }
}
