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
use c975L\SocialBundle\Namer\SocialMediaNamer;
use c975L\UiBundle\Contract\PickableMediaProviderInterface;
use c975L\UiBundle\Model\PickableMedia;
use Symfony\Contracts\Translation\TranslatableInterface;

// The site's own pictures and videos a post takes, from the libraries other bundles offer (a gallery): a picture copied as the post's own JPEG, so it suits every network and goes with the purge, a video only referenced, too heavy to copy
class SocialMediaPicker
{
    // How many medias each library shows, the search narrowing them
    public const int LIMIT = 48;

    /** @param iterable<PickableMediaProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly SocialMediaFile $mediaFile,
        private readonly string $projectDir,
    ) {
    }

    public function hasLibraries(): bool
    {
        foreach ($this->providers as $provider) {
            return true;
        }

        return false;
    }

    // Each library's medias matching the search, the latest first
    /** @return list<array{label: TranslatableInterface, medias: list<PickableMedia>}> */
    public function libraries(string $search): array
    {
        $libraries = [];
        foreach ($this->providers as $provider) {
            $libraries[] = ['label' => $provider->getPickableMediaLabel(), 'medias' => $provider->findPickableMedia($search, self::LIMIT)];
        }

        return $libraries;
    }

    // Adds after the post's medias those picked among the ones the same search offers - a path typed by hand being no file of the site to take - and says how many it added
    /** @param list<string> $paths */
    public function attach(SocialPost $post, string $search, array $paths): int
    {
        $offered = [];
        foreach ($this->libraries($search) as $library) {
            foreach ($library['medias'] as $media) {
                $offered[$media->path] = $media;
            }
        }

        $added = 0;
        foreach (array_unique($paths) as $path) {
            $media = isset($offered[$path]) ? $this->toSocialMedia($post, $offered[$path]) : null;
            if (null !== $media) {
                $post->addMedia($media->setPosition($post->getMedias()->count()));
                ++$added;
            }
        }

        return $added;
    }

    // Null for a file gone from the disk since the library listed it
    private function toSocialMedia(SocialPost $post, PickableMedia $picked): ?SocialMedia
    {
        $source = $this->projectDir . '/public/' . ltrim($picked->path, '/');
        if (!is_file($source)) {
            return null;
        }

        if (str_starts_with($picked->mimeType, 'video/')) {
            return SocialMedia::reference($post, $picked->path, $picked->mimeType, ...$this->mediaFile->measure($source, true))
                ->setSize((int) filesize($source))
                ->setAlt($picked->title);
        }

        $name = SocialMediaNamer::DIRECTORY . '/' . bin2hex(random_bytes(16)) . '.jpg';
        $target = $this->projectDir . '/public/' . $name;
        if (!is_dir(\dirname($target))) {
            mkdir(\dirname($target), 0o755, true);
        }
        copy($source, $target);
        $this->mediaFile->toJpeg($target);
        [$width, $height] = $this->mediaFile->measure($target, false);

        return new SocialMedia($post)
            ->setFilename($name)
            ->setMimeType('image/jpeg')
            ->setSize((int) filesize($target))
            ->setDimensions($width, $height)
            ->setAlt($picked->title);
    }
}
