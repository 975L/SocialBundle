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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\File;

// Drafts written elsewhere - each its own text, picture and first comment, which no series draws from the site's contents - made at once from a JSON file (see c975l:social:draft), as one series so that its ending is announced like any other. Never sent before being read and approved, as every draft
class SocialDraftImporter
{
    public function __construct(
        private readonly SocialPublisher $socialPublisher,
        private readonly SocialSeriesGenerator $seriesGenerator,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $projectDir,
    ) {
    }

    // The drafts the file describes, checked before anything is written - every mistake listed at once, nothing written while there is one. $baseDir is where the pictures' paths start
    /**
     * @param array<mixed, mixed> $data
     *
     * @return array{errors: list<string>, drafts: list<array{at: \DateTimeImmutable, networks: list<string>, text: string, comment: ?string, url: ?string, media: ?string}>}
     */
    public function read(array $data, string $baseDir): array
    {
        $errors = [];
        $drafts = [];
        $connected = $this->socialPublisher->getConnectedNetworkNames();
        $data += ['title' => '', 'posts' => null];
        $posts = \is_array($data['posts']) ? $data['posts'] : [];
        if ('' === trim((string) $data['title'])) {
            $errors[] = 'The file has no "title".';
        }
        if ([] === $posts) {
            $errors[] = 'The file has no "posts".';
        }

        foreach ($posts as $index => $post) {
            $draft = $this->draft(\is_array($post) ? $post : [], $baseDir);
            $errors = [...$errors, ...$this->draftErrors(sprintf('Post %d', (int) $index + 1), $draft, $connected)];
            if (null !== $draft['at']) {
                $drafts[] = $draft;
            }
        }

        return ['errors' => $errors, 'drafts' => [] === $errors ? $drafts : []];
    }

    // One post of the file as a draft, its moment null when it is not one
    /**
     * @param array<mixed, mixed> $post
     *
     * @return array{at: ?\DateTimeImmutable, networks: list<string>, text: string, comment: ?string, url: ?string, media: ?string}
     */
    private function draft(array $post, string $baseDir): array
    {
        $post += ['at' => null, 'networks' => null, 'text' => '', 'comment' => '', 'url' => '', 'media' => ''];
        $comment = trim((string) $post['comment']);
        $url = trim((string) $post['url']);
        $media = trim((string) $post['media']);

        return [
            'at' => $this->moment($post['at']),
            'networks' => \is_array($post['networks']) ? array_values(array_map(strval(...), $post['networks'])) : [],
            'text' => trim((string) $post['text']),
            'comment' => '' === $comment ? null : $comment,
            'url' => '' === $url ? null : $url,
            'media' => '' === $media ? null : rtrim($baseDir, '/') . '/' . ltrim($media, '/'),
        ];
    }

    // What is wrong with a draft, $name saying which post of the file it is
    /**
     * @param array{at: ?\DateTimeImmutable, networks: list<string>, text: string, comment: ?string, url: ?string, media: ?string} $draft
     * @param list<string>                                                                                                         $connected
     *
     * @return list<string>
     */
    private function draftErrors(string $name, array $draft, array $connected): array
    {
        $errors = [];
        if (null === $draft['at']) {
            $errors[] = $name . ': "at" is not a date and time written "YYYY-MM-DD HH:MM".';
        } elseif ($draft['at'] <= new \DateTimeImmutable()) {
            $errors[] = $name . ': "at" is in the past.';
        }
        if ('' === $draft['text']) {
            $errors[] = $name . ': "text" is empty.';
        }
        if ([] === $draft['networks'] || [] !== array_diff($draft['networks'], $connected)) {
            $errors[] = sprintf('%s: "networks" must list connected networks among %s.', $name, implode(', ', $connected));
        }
        if (null !== $draft['media'] && !is_file($draft['media'])) {
            $errors[] = sprintf('%s: the media "%s" does not exist.', $name, $draft['media']);
        }

        return $errors;
    }

    // The series and its drafts, saved - each picture copied, the file given being left where it is
    /**
     * @param list<array{at: \DateTimeImmutable, networks: list<string>, text: string, comment: ?string, url: ?string, media: ?string}> $drafts
     *
     * @return list<SocialPost>
     */
    public function import(string $title, array $drafts): array
    {
        $networks = array_values(array_unique(array_merge(...array_column($drafts, 'networks'))));
        $series = new SocialSeries($title, \count($drafts), 'days', 1, [], SocialSeriesGenerator::MODE_IMPORT, '', [], $networks);
        $this->entityManager->persist($series);

        $posts = [];
        foreach ($drafts as $draft) {
            $post = $this->socialPublisher->createManual($draft['at']);
            $post->setSeries($series);
            $post->setNetworks($draft['networks']);
            $post->setText($draft['text']);
            $post->setUrl($draft['url']);
            $this->socialPublisher->rewriteTargets($post);
            $this->socialPublisher->addTargets($post, $post->takeAddedNetworks());
            foreach ($post->getTargets() as $target) {
                if ($this->socialPublisher->supportsComment($target->getNetwork())) {
                    $target->setComment($draft['comment']);
                }
            }
            if (null !== $draft['media']) {
                $this->addMedia($post, $draft['media']);
            }
            $this->entityManager->persist($post);
            $posts[] = $post;
        }
        $this->entityManager->flush();

        return $posts;
    }

    // The picture copied then stored as a series' media is, the post referencing it
    private function addMedia(SocialPost $post, string $path): void
    {
        $copy = sys_get_temp_dir() . '/social-' . bin2hex(random_bytes(8)) . '.' . pathinfo($path, \PATHINFO_EXTENSION);
        copy($path, $copy);
        [$name, $mimeType, $width, $height, $duration] = $this->seriesGenerator->store(new File($copy));
        $post->addMedia(SocialMedia::reference($post, $name, $mimeType, $width, $height, $duration)->setSize((int) filesize($this->publicPath($name))));
    }

    private function publicPath(string $name): string
    {
        return $this->projectDir . '/public/' . $name;
    }

    // A date and time written as the command's help says, in the site's timezone - null for anything else, a relative date or one PHP would roll over included
    private function moment(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }

        $moment = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', trim($value));

        return false !== $moment && $moment->format('Y-m-d H:i') === trim($value) ? $moment : null;
    }
}
