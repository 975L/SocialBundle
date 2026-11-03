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
use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Repository\SocialSeriesRepository;
use c975L\UiBundle\Model\SocialContent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\File;

// Drafts written elsewhere - each its own text, a network's own one in "texts" (kept until "text" changes once taken out of the file, as a text corrected by hand), picture, first comment, reshare on the member's profile and story, which no series draws from the site's contents - made at once from a JSON file (see c975l:social:draft), as one series so that its ending is announced like any other. Never sent before being read and approved, as every draft. A post given a "key" is imported again rather than twice: left as it is when unchanged, updated and taken back to a draft when it changed, never touched once it went out, taken back to a draft when its key left the file - a file gives every post a key, or none. A post saying the content it was written from ("source": "book:12") holds it as a series' draft does, so that nothing else posts it again - a content held by a post to come of another series being refused, the posts of the file sharing one at will - and goes out with its own picture alone
class SocialDraftImporter
{
    // The pictures' sha1, by path, each file read once
    /** @var array<string, string|false> */
    private array $hashes = [];

    public function __construct(
        private readonly SocialPublisher $socialPublisher,
        private readonly SocialSeriesGenerator $seriesGenerator,
        private readonly EntityManagerInterface $entityManager,
        private readonly SocialPostRepository $postRepository,
        private readonly SocialSeriesRepository $seriesRepository,
        private readonly string $projectDir,
    ) {
    }

    // The drafts the file describes, checked before anything is written - every mistake listed at once, nothing written while there is one, a keyed post already gone out never checked since it is left as it went. The notes say what the import will do that the file may not expect. $baseDir is where the pictures' paths start
    /**
     * @param array<mixed, mixed> $data
     *
     * @return array{errors: list<string>, notes: list<string>, drafts: list<array{at: \DateTimeImmutable, networks: list<string>, text: string, comment: ?string, reshare: ?string, story: bool, url: ?string, media: ?string, key: ?string, source: ?string, repost: ?string, texts: array<string, string>}>}
     */
    public function read(array $data, string $baseDir): array
    {
        $errors = [];
        $connected = $this->socialPublisher->getConnectedNetworkNames();
        $data += ['title' => '', 'posts' => null];
        $posts = \is_array($data['posts']) ? $data['posts'] : [];
        if ('' === trim((string) $data['title'])) {
            $errors[] = 'The file has no "title".';
        }
        if ([] === $posts) {
            $errors[] = 'The file has no "posts".';
        }

        $all = [];
        $series = $this->findSeries(trim((string) $data['title']));
        $seriesPosts = $this->seriesPosts($series);
        foreach (array_values($posts) as $index => $post) {
            $draft = $this->draft(\is_array($post) ? $post : [], $baseDir);
            $found = $this->findPost($seriesPosts, $draft);
            if (null === $draft['at'] || true !== $found?->hasGoneOut()) {
                $errors = [...$errors, ...$this->draftErrors(sprintf('Post %d', $index + 1), $draft, $connected, $series)];
            }
            $all[] = $draft;
        }
        $errors = [...$errors, ...$this->fileErrors($all)];
        $drafts = array_values(array_filter($all, static fn (array $draft): bool => null !== $draft['at']));

        return ['errors' => $errors, 'notes' => $this->notes($series, array_values(array_filter(array_column($all, 'key'))), $seriesPosts), 'drafts' => [] === $errors ? $drafts : []];
    }

    // What is wrong across the posts of the file: a key given twice - a source may be shared, the same content going out on several networks or days - and a post without a key beside keyed ones, which would be made again at each import
    /**
     * @param list<array{at: ?\DateTimeImmutable, networks: list<string>, text: string, comment: ?string, reshare: ?string, story: bool, url: ?string, media: ?string, key: ?string, source: ?string, repost: ?string, texts: array<string, string>}> $drafts
     *
     * @return list<string>
     */
    private function fileErrors(array $drafts): array
    {
        $errors = [];
        $seen = ['key' => []];
        $keyed = [] !== array_filter(array_column($drafts, 'key'));
        foreach ($drafts as $index => $draft) {
            $name = sprintf('Post %d', $index + 1);
            foreach (array_keys($seen) as $field) {
                $value = $draft[$field];
                if (null !== $value && isset($seen[$field][$value])) {
                    $errors[] = sprintf('%s: the %s "%s" is already the one of post %d.', $name, $field, $value, $seen[$field][$value]);
                }
                if (null !== $value) {
                    $seen[$field][$value] ??= $index + 1;
                }
            }
            if ($keyed && null === $draft['key']) {
                $errors[] = sprintf('%s: "key" is missing, the other posts giving one.', $name);
            }
        }

        return $errors;
    }

    // What the import will do that the file may not expect: a series of its title already there, which a file without any key does not take up, and the posts to come whose key left the file, taken back to drafts
    /**
     * @param list<string>     $keys
     * @param list<SocialPost> $seriesPosts
     *
     * @return list<string>
     */
    private function notes(?SocialSeries $series, array $keys, array $seriesPosts): array
    {
        if (null === $series) {
            return [];
        }
        if ([] === $keys) {
            return [sprintf('A series "%s" was already imported: the file, giving no post a "key", is imported as a new series. Give the posts a key to update them instead.', $series->getTitle())];
        }

        $orphans = array_map(static fn (SocialPost $post): string => (string) $post->getImportKey(), $this->orphans($seriesPosts, $keys));

        return [] === $orphans ? [] : [sprintf('Posts "%s" are no longer in the file: taken back to drafts, delete them on the calendar if they should not go.', implode('", "', $orphans))];
    }

    // The posts of the series still to come, not gone out anywhere, whose key the file no longer gives
    /**
     * @param list<SocialPost> $seriesPosts
     * @param list<string>     $keys
     *
     * @return list<SocialPost>
     */
    private function orphans(array $seriesPosts, array $keys): array
    {
        $now = new \DateTimeImmutable();

        return array_values(array_filter($seriesPosts, static fn (SocialPost $post): bool => null !== $post->getImportKey()
            && !\in_array($post->getImportKey(), $keys, true)
            && $post->getPlannedAt() > $now
            && !$post->hasGoneOut()));
    }

    // One post of the file as a draft, its moment null when it is not one
    /**
     * @param array<mixed, mixed> $post
     *
     * @return array{at: ?\DateTimeImmutable, networks: list<string>, text: string, comment: ?string, reshare: ?string, story: bool, url: ?string, media: ?string, key: ?string, source: ?string, repost: ?string, texts: array<string, string>}
     */
    private function draft(array $post, string $baseDir): array
    {
        $post += ['at' => null, 'networks' => null, 'text' => '', 'comment' => '', 'reshare' => '', 'story' => false, 'url' => '', 'media' => '', 'key' => '', 'source' => '', 'repost' => '', 'texts' => []];
        $comment = trim((string) $post['comment']);
        $reshare = trim((string) $post['reshare']);
        $url = trim((string) $post['url']);
        $media = trim((string) $post['media']);
        $key = mb_substr(trim((string) $post['key']), 0, 190);
        $source = trim((string) $post['source']);
        $repost = trim((string) $post['repost']);

        return [
            'at' => $this->moment($post['at']),
            'networks' => \is_array($post['networks']) ? array_values(array_map(strval(...), $post['networks'])) : [],
            'text' => trim((string) $post['text']),
            'comment' => '' === $comment ? null : $comment,
            'reshare' => '' === $reshare ? null : $reshare,
            'story' => true === $post['story'],
            'url' => '' === $url ? null : $url,
            'media' => '' === $media ? null : rtrim($baseDir, '/') . '/' . ltrim($media, '/'),
            'key' => '' === $key ? null : $key,
            'source' => '' === $source ? null : $source,
            'repost' => '' === $repost ? null : $repost,
            // Anything but an object of texts comes out named after no network, or empty, and so refused
            'texts' => array_map(static fn (mixed $text): string => \is_scalar($text) ? trim((string) $text) : '', (array) $post['texts']),
        ];
    }

    // What is wrong with a draft, $name saying which post of the file it is, $series the one a former import made of the file
    /**
     * @param array{at: ?\DateTimeImmutable, networks: list<string>, text: string, comment: ?string, reshare: ?string, story: bool, url: ?string, media: ?string, key: ?string, source: ?string, repost: ?string, texts: array<string, string>} $draft
     * @param list<string>                                                                                                                                                                                                                      $connected
     *
     * @return list<string>
     */
    private function draftErrors(string $name, array $draft, array $connected, ?SocialSeries $series): array
    {
        $errors = [];
        if (null === $draft['at']) {
            $errors[] = $name . ': "at" is not a date and time written "YYYY-MM-DD HH:MM".';
        } elseif (null === $draft['key'] && $draft['at'] <= new \DateTimeImmutable()) {
            // A keyed post gone by is left to the import, which never touches it: a file imported again keeps its past posts
            $errors[] = $name . ': "at" is in the past.';
        }
        if ('' === $draft['text']) {
            $errors[] = $name . ': "text" is empty.';
        }
        if ([] === $draft['networks'] || [] !== array_diff($draft['networks'], $connected)) {
            $errors[] = sprintf('%s: "networks" must list connected networks among %s.', $name, implode(', ', $connected));
        }
        if (null !== $draft['source'] && null === $this->content($draft['source'])) {
            $errors[] = sprintf('%s: the source "%s" is no content of this site, written "<source type>:<id>" (see c975l:social:contents).', $name, $draft['source']);
        } elseif (null !== $draft['source']) {
            [$type, $id] = explode(':', $draft['source'], 2);
            if ($this->socialPublisher->isHeldOutside($type, $id, $series)) {
                $errors[] = sprintf('%s: the source "%s" is already held by a post to come of another series (see c975l:social:contents).', $name, $draft['source']);
            }
        }

        return [...$errors, ...$this->attachedErrors($name, $draft)];
    }

    // What is wrong with what a draft points at: a picture that is not there, a link no post may hold
    /**
     * @param array{at: ?\DateTimeImmutable, networks: list<string>, text: string, comment: ?string, reshare: ?string, story: bool, url: ?string, media: ?string, key: ?string, source: ?string, repost: ?string, texts: array<string, string>} $draft
     *
     * @return list<string>
     */
    private function attachedErrors(string $name, array $draft): array
    {
        $errors = [];
        if (null !== $draft['media'] && !is_file($draft['media'])) {
            $errors[] = sprintf('%s: the media "%s" does not exist.', $name, $draft['media']);
        }
        if (null !== $draft['url'] && !PublicWeb::isAddress($draft['url'])) {
            $errors[] = $name . ': "url" is not an http or https address.';
        }

        return [...$errors, ...$this->repostErrors($name, $draft), ...$this->textsErrors($name, $draft)];
    }

    // What is wrong with a network's own text: a network the draft does not go to, a text empty or too long for it
    /**
     * @param array{at: ?\DateTimeImmutable, networks: list<string>, text: string, comment: ?string, reshare: ?string, story: bool, url: ?string, media: ?string, key: ?string, source: ?string, repost: ?string, texts: array<string, string>} $draft
     *
     * @return list<string>
     */
    private function textsErrors(string $name, array $draft): array
    {
        $errors = [];
        foreach ($draft['texts'] as $network => $text) {
            $maxLength = $this->socialPublisher->getMaxLength((string) $network);
            if (!\in_array((string) $network, $draft['networks'], true)) {
                $errors[] = sprintf('%s: "texts" names %s, which is not among its "networks".', $name, $network);
            } elseif ('' === $text) {
                $errors[] = sprintf('%s: the text of %s is empty.', $name, $network);
            } elseif (null !== $maxLength && mb_strlen($text) > $maxLength) {
                $errors[] = sprintf('%s: the text of %s is longer than its %d characters.', $name, $network, $maxLength);
            }
        }

        return $errors;
    }

    // What keeps a draft from reposting: the link the post is found by, and a connection able to, one granted before reposting came to be connected again
    /**
     * @param array{at: ?\DateTimeImmutable, networks: list<string>, text: string, comment: ?string, reshare: ?string, story: bool, url: ?string, media: ?string, key: ?string, source: ?string, repost: ?string, texts: array<string, string>} $draft
     *
     * @return list<string>
     */
    private function repostErrors(string $name, array $draft): array
    {
        if (null === $draft['repost']) {
            return [];
        }

        $errors = null === $draft['url'] ? [$name . ': "repost" needs the "url" its post carries.'] : [];
        foreach ($draft['networks'] as $network) {
            if (!$this->socialPublisher->supportsRepost($network)) {
                $errors[] = sprintf('%s: %s cannot repost, connect it again if it should.', $name, $network);
            }
        }

        return $errors;
    }

    // The series and its drafts, saved - each picture copied, the file given being left where it is. The series of the same title is taken up again when the file gives a post a key, a keyed post found in it imported again rather than made twice; a file without any key makes a new series
    /**
     * @param list<array{at: \DateTimeImmutable, networks: list<string>, text: string, comment: ?string, reshare: ?string, story: bool, url: ?string, media: ?string, key: ?string, source: ?string, repost: ?string, texts: array<string, string>}> $drafts
     *
     * @return array{created: list<SocialPost>, updated: list<SocialPost>, unchanged: int, out: int, past: int}
     */
    public function import(string $title, array $drafts): array
    {
        $keys = array_values(array_filter(array_column($drafts, 'key')));
        $series = [] === $keys ? null : $this->findSeries($title);
        $seriesPosts = $this->seriesPosts($series);
        $networks = array_values(array_unique(array_merge(...array_column($drafts, 'networks'))));
        if (null === $series) {
            $series = new SocialSeries($title, \count($drafts), 'days', 1, [], SocialSeriesGenerator::MODE_IMPORT, '', [], $networks);
            $this->entityManager->persist($series);
        }

        $result = ['created' => [], 'updated' => [], 'unchanged' => 0, 'out' => 0, 'past' => 0];
        $now = new \DateTimeImmutable();
        foreach ($drafts as $draft) {
            $hash = $this->hash($draft);
            $post = $this->findPost($seriesPosts, $draft);
            $outcome = $this->outcome($post, $draft, $hash, $now);
            switch ($outcome) {
                // A post a former import made without a key, unchanged: it takes its key and the content it names, approval kept
                case 'adopted':
                    $this->writeSource($post, $draft);
                    $post->setImport($draft['key'], $hash);
                    ++$result['unchanged'];
                    break;
                case 'created':
                    $post = $this->socialPublisher->createManual($draft['at']);
                    $post->setSeries($series);
                    $this->write($post, $draft);
                    $post->setImport($draft['key'], $hash);
                    $this->entityManager->persist($post);
                    $result['created'][] = $post;
                    break;
                case 'updated':
                    $post->setPlannedAt($draft['at']);
                    $this->write($post, $draft);
                    $post->unapprove();
                    $post->setImport($draft['key'], $hash);
                    $result['updated'][] = $post;
                    break;
                default:
                    ++$result[$outcome];
            }
        }

        // A series taken up again: the posts whose key left the file wait for a reading again, and its page counts what it now holds
        if ([] !== $seriesPosts) {
            foreach ($this->orphans($seriesPosts, $keys) as $orphan) {
                $orphan->unapprove();
            }
            $series->setImported(\count($seriesPosts) + \count($result['created']), array_values(array_unique([...$series->getNetworks(), ...$networks])));
        }
        $this->entityManager->flush();

        return $result;
    }

    // What importing a draft does to the post found for it: taken up without a key, left gone by, made, left gone out, left unchanged, or updated
    /** @param array{at: \DateTimeImmutable, networks: list<string>, text: string, comment: ?string, reshare: ?string, story: bool, url: ?string, media: ?string, key: ?string, source: ?string, repost: ?string, texts: array<string, string>} $draft */
    private function outcome(?SocialPost $post, array $draft, string $hash, \DateTimeImmutable $now): string
    {
        return match (true) {
            null !== $post && null === $post->getImportKey() && !$post->hasGoneOut() && $this->sameAs($post, $draft) => 'adopted',
            $draft['at'] <= $now && true !== $post?->hasGoneOut() => 'past',
            null === $post => 'created',
            $post->hasGoneOut() => 'out',
            $post->getImportHash() === $hash => 'unchanged',
            default => 'updated',
        };
    }

    // The content a draft names held by its post, else none any more - its link the draft's, else the content's, and its picture the draft's alone: a post without "media" never goes out with the content's
    /** @param array{at: \DateTimeImmutable, networks: list<string>, text: string, comment: ?string, reshare: ?string, story: bool, url: ?string, media: ?string, key: ?string, source: ?string, repost: ?string, texts: array<string, string>} $draft */
    private function writeSource(SocialPost $post, array $draft): void
    {
        $content = null === $draft['source'] ? null : $this->content($draft['source']);
        if (null !== $content) {
            $post->takeSource(explode(':', (string) $draft['source'], 2)[0], $content);
        } elseif (!$post->isManual()) {
            $post->dropSource();
        }
        if (null === $draft['media']) {
            $post->dropImageUrl();
        }
        $post->setUrl($draft['url'] ?? $content?->url);
    }

    // The imported series of that title a keyed file takes up: the latest one made with keys, else the latest one
    private function findSeries(string $title): ?SocialSeries
    {
        return '' === $title ? null : $this->seriesRepository->findLastKeyedImport($title) ?? $this->seriesRepository->findOneBy(['title' => $title, 'mode' => SocialSeriesGenerator::MODE_IMPORT], ['id' => 'DESC']);
    }

    // Every post of a saved series, read once for the whole file
    /** @return list<SocialPost> */
    private function seriesPosts(?SocialSeries $series): array
    {
        return null === $series?->getId() ? [] : $this->postRepository->findBy(['series' => $series]);
    }

    // The post a former import of the series gave the draft's key, else one it made without a key at the same moment on the same networks - none for a draft without a key
    /**
     * @param list<SocialPost>                                                                                                                                                                                                                  $seriesPosts
     * @param array{at: ?\DateTimeImmutable, networks: list<string>, text: string, comment: ?string, reshare: ?string, story: bool, url: ?string, media: ?string, key: ?string, source: ?string, repost: ?string, texts: array<string, string>} $draft
     */
    private function findPost(array $seriesPosts, array $draft): ?SocialPost
    {
        if (null === $draft['key'] || null === $draft['at']) {
            return null;
        }

        foreach ($seriesPosts as $post) {
            if ($post->getImportKey() === $draft['key']) {
                return $post;
            }
        }

        $networks = $draft['networks'];
        sort($networks);
        foreach ($seriesPosts as $post) {
            // Another draft of the same import may have taken it already, its key not flushed yet
            if (null !== $post->getImportKey() || $post->getPlannedAt()->getTimestamp() !== $draft['at']->getTimestamp()) {
                continue;
            }
            $own = $post->getNetworks();
            sort($own);
            if ($own === $networks) {
                return $post;
            }
        }

        return null;
    }

    // Whether a post already says everything the draft writes - its networks being the draft's ones: its text, its link, its picture read by its content, and the first comment, reshare and story of each target still to go where the network offers them
    /** @param array{at: \DateTimeImmutable, networks: list<string>, text: string, comment: ?string, reshare: ?string, story: bool, url: ?string, media: ?string, key: ?string, source: ?string, repost: ?string, texts: array<string, string>} $draft */
    private function sameAs(SocialPost $post, array $draft): bool
    {
        if ($post->getText() !== $draft['text'] || $post->getUrl() !== (string) $draft['url'] || !$this->sameMedia($post, $draft['media'])) {
            return false;
        }
        foreach ($post->getTargets() as $target) {
            $network = $target->getNetwork();
            if (!$target->isPending()) {
                continue;
            }
            if ($this->targetDiffers($target, $network, $draft)) {
                return false;
            }
        }

        return true;
    }

    // Whether a target still to go says something else than the draft, where the network offers it
    /** @param array{at: \DateTimeImmutable, networks: list<string>, text: string, comment: ?string, reshare: ?string, story: bool, url: ?string, media: ?string, key: ?string, source: ?string, repost: ?string, texts: array<string, string>} $draft */
    private function targetDiffers(SocialPostTarget $target, string $network, array $draft): bool
    {
        return ($this->socialPublisher->supportsComment($network) && $target->getComment() !== $draft['comment'])
            || ($this->socialPublisher->supportsReshare($network) && $target->getReshare() !== $draft['reshare'])
            || ($this->socialPublisher->supportsStory($network) && $target->isStory() !== $draft['story'])
            || ($this->socialPublisher->supportsRepost($network) && $target->getRepost() !== $draft['repost'])
            || (isset($draft['texts'][$network]) && $target->getText() !== $draft['texts'][$network]);
    }

    // Whether the post's only picture is the one at $path, read by its content - no picture and no $path being the same too
    private function sameMedia(SocialPost $post, ?string $path): bool
    {
        if ($post->getMedias()->count() > 1) {
            return false;
        }
        $media = $post->getMedias()->first();
        $file = false === $media || null === $media->getFilename() ? null : $this->publicPath($media->getFilename());

        return null === $file ? null === $path : null !== $path && is_file($file) && $this->mediaHash($file) === $this->mediaHash($path);
    }

    // A picture's sha1, each file read once
    private function mediaHash(string $path): string | false
    {
        return $this->hashes[$path] ??= sha1_file($path);
    }

    // A draft written on its post: networks, text, a network's own one, link, first comment, reshare and story where the network offers them, and its picture in place of any other - kept rather than copied again when it is the same
    /** @param array{at: \DateTimeImmutable, networks: list<string>, text: string, comment: ?string, reshare: ?string, story: bool, url: ?string, media: ?string, key: ?string, source: ?string, repost: ?string, texts: array<string, string>} $draft */
    private function write(SocialPost $post, array $draft): void
    {
        $this->writeSource($post, $draft);
        $post->setNetworks($draft['networks']);
        $post->setText($draft['text']);
        $this->socialPublisher->rewriteTargets($post);
        $this->socialPublisher->addTargets($post, $post->takeAddedNetworks());
        foreach ($post->getTargets() as $target) {
            if (!$target->isPending()) {
                continue;
            }
            $target->setText($draft['texts'][$target->getNetwork()] ?? $target->getText());
            if ($this->socialPublisher->supportsComment($target->getNetwork())) {
                $target->setComment($draft['comment']);
            }
            if ($this->socialPublisher->supportsReshare($target->getNetwork())) {
                $target->setReshare($draft['reshare']);
            }
            if ($this->socialPublisher->supportsStory($target->getNetwork())) {
                $target->setStory($draft['story']);
            }
            if ($this->socialPublisher->supportsRepost($target->getNetwork())) {
                $target->setRepost($draft['repost']);
            }
        }
        if ($this->sameMedia($post, $draft['media'])) {
            return;
        }
        foreach ($post->getMedias()->toArray() as $media) {
            $post->removeMedia($media);
        }
        if (null !== $draft['media']) {
            $this->addMedia($post, $draft['media']);
        }
    }

    // What a draft writes, its networks in any order and its picture read by its content rather than its name - by its name when gone, as for a post already out
    /** @param array{at: \DateTimeImmutable, networks: list<string>, text: string, comment: ?string, reshare: ?string, story: bool, url: ?string, media: ?string, key: ?string, source: ?string, repost: ?string, texts: array<string, string>} $draft */
    private function hash(array $draft): string
    {
        $draft['at'] = $draft['at']->format('Y-m-d H:i');
        sort($draft['networks']);
        $draft['media'] = null === $draft['media'] || !is_file($draft['media']) ? $draft['media'] : $this->mediaHash($draft['media']);
        // No repost leaves the hash a former import stored as it was, an unchanged file staying unchanged
        if (null === $draft['repost']) {
            unset($draft['repost']);
        }
        // No own text either, and own texts in any order
        ksort($draft['texts']);
        if ([] === $draft['texts']) {
            unset($draft['texts']);
        }

        return hash('sha256', (string) json_encode($draft));
    }

    // The content a draft says it was written from, "<source type>:<id>" - null for one this site does not have
    private function content(string $source): ?SocialContent
    {
        [$type, $id] = explode(':', $source, 2) + [1 => ''];

        return '' === $id ? null : $this->socialPublisher->findContent($type, $id);
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
