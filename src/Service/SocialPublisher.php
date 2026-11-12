<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\ConfigBundle\Service\SiteUrlResolver;
use c975L\SocialBundle\Contract\FirstCommentPublisherInterface;
use c975L\SocialBundle\Contract\NetworkPublisherInterface;
use c975L\SocialBundle\Contract\PostOptionsPublisherInterface;
use c975L\SocialBundle\Contract\ProfileResharePublisherInterface;
use c975L\SocialBundle\Contract\RepostPublisherInterface;
use c975L\SocialBundle\Contract\StoryPublisherInterface;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Enum\SocialPostStatus;
use c975L\SocialBundle\Model\MediaRules;
use c975L\SocialBundle\Model\PostMedia;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\UiBundle\Contract\BrowsableSocialContentSourceInterface;
use c975L\UiBundle\Contract\DatedSocialContentSourceInterface;
use c975L\UiBundle\Contract\ScopedSocialContentSourceInterface;
use c975L\UiBundle\Contract\SocialContentSourceInterface;
use c975L\UiBundle\Model\SocialContent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Translation\TranslatableMessage;

// The publication on the site's networks: prepares a post as a draft planned for a moment - one target per configured network, each with its own text - which the planned run sends once approved, or the screen's "Publish" at once. Every method answers the same report, keyed by network: ['status' => a SocialPostStatus value or "dry_run", 'message' => the post's id there or the network's refusal, 'payload' => what would be sent, on a dry run only]
class SocialPublisher
{
    public const string DRY_RUN = 'dry_run';

    // The post the last prepare() saved, its report being keyed by network
    private ?SocialPost $prepared = null;

    /**
     * @param iterable<SocialContentSourceInterface> $sources
     * @param iterable<NetworkPublisherInterface>    $networks
     */
    public function __construct(
        private readonly iterable $sources,
        private readonly iterable $networks,
        private readonly SocialPostTextBuilder $textBuilder,
        private readonly SocialPageReader $pageReader,
        private readonly SocialPostRepository $postRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly LockFactory $lockFactory,
        private readonly SocialPostWriter $writer,
        private readonly SiteUrlResolver $siteUrlResolver,
        private readonly string $projectDir,
    ) {
    }

    // What a batch of drafts may be prepared from: each source whole, then each of its groups, labels keyed by the value prepareDrafts() takes
    /** @return array<string, string> */
    public function getSourceChoices(): array
    {
        $choices = [];
        foreach ($this->sources as $source) {
            $type = $source->getSourceType();
            $label = ucfirst(str_replace('_', ' ', $type));
            $choices[$type] = $label;
            if ($source instanceof ScopedSocialContentSourceInterface) {
                // Two groups of the same name would otherwise share one label, the first then lost to array_flip()
                foreach ($source->getScopes() as $id => $scope) {
                    $scopeLabel = $label . ' - ' . $scope;
                    $choices[$type . ':' . $id] = \in_array($scopeLabel, $choices, true) ? $scopeLabel . ' (#' . $id . ')' : $scopeLabel;
                }
            }
        }

        return $choices;
    }

    // The networks connected on this site, the ones a post may be sent on
    /** @return list<string> */
    public function getConnectedNetworkNames(): array
    {
        return array_keys($this->networksByName());
    }

    // Every network a post may go to, configured or not yet
    /** @return list<string> */
    public function getNetworkNames(): array
    {
        return array_keys($this->allNetworks());
    }

    // The planned run, every quarter of an hour: every approved post whose moment has come, sent on all its approved networks, keyed "#<post id> <network>" - each result carrying its post's id and its network, the key being only what is printed
    /** @return array<string, array<string, mixed>> */
    public function publishPlanned(bool $dryRun = false): array
    {
        $now = new \DateTimeImmutable();
        $report = [];
        foreach ($this->postRepository->findApproved() as $post) {
            if ($post->getPlannedAt() > $now) {
                continue;
            }

            foreach ($this->sendTargets($post, $post->getApprovedNetworks(), SocialPostStatus::Approved, $dryRun) ?? [] as $network => $result) {
                $report['#' . $post->getId() . ' ' . $network] = $result + ['post' => $post->getId(), 'network' => $network];
            }
        }

        return $report;
    }

    // One draft per moment, the next content each time, $sources narrowing them to some sources or groups of them (the keys of getSourceChoices()) - never sent until approved. Fewer when the contents run out, a dated source only passing over the moments without content, none while no network is connected
    /**
     * @param list<\DateTimeImmutable> $moments
     * @param list<string>             $sources
     *
     * @return list<SocialPost>
     */
    public function prepareDrafts(array $moments, array $sources = []): array
    {
        $posts = [];
        $passesOver = $this->hasDatedSource($sources);
        foreach ($moments as $moment) {
            [$sourceType, $content] = $this->hasConnectedNetwork() ? $this->nextContent($this->scopesOf($sources), $moment) : ['', null];
            if (null === $content) {
                if ($passesOver) {
                    continue;
                }
                break;
            }

            $this->prepare($sourceType, $content, false, $moment);
            if (null === $this->prepared) {
                break;
            }
            $posts[] = $this->prepared;
        }

        return $posts;
    }

    // Prepares a post of any page, read from its Open Graph tags, as a draft planned at $at
    /** @return array<string, array<string, mixed>> */
    public function prepareUrl(string $url, \DateTimeImmutable $at, bool $dryRun = false): array
    {
        // Its drafts would have no network to go out on
        if (!$dryRun && !$this->hasConnectedNetwork()) {
            throw new \RuntimeException('No network is connected yet: connect one on the "Connections" screen first.');
        }

        return $this->prepare(SocialPost::SOURCE_URL, $this->pageReader->read($url), $dryRun, $at);
    }

    // The screen's "Publish": sends every target of the post not published yet, a draft as well as a failed one - null while another "Publish" of the same post is still sending it
    /** @return ?array<string, array<string, mixed>> */
    public function publish(SocialPost $post): ?array
    {
        // Nothing is saved before every network has answered, Instagram alone taking up to half a minute: a second click meanwhile would find the targets still pending and post them twice
        $lock = $this->lockFactory->createLock('social_post_publish_' . $post->getId());
        if (!$lock->acquire()) {
            return null;
        }

        try {
            // What a "Publish" finished just before this one has saved, rather than the statuses this request read before it
            foreach ($post->getTargets() as $target) {
                $this->entityManager->refresh($target);
            }

            $content = $this->contentOf($post);
            $networks = $this->networksByName();

            $report = [];
            foreach ($post->getTargets() as $target) {
                if ($target->isPending()) {
                    $this->send($networks[$target->getNetwork()] ?? null, $target, $content);
                } elseif ($target->isFollowUpPending()) {
                    $this->sendFollowUps($networks[$target->getNetwork()] ?? null, $target, $content);
                }
                // Saved after each network, so a process killed later never sends again what already went out
                $this->entityManager->flush();
                $report[$target->getNetwork()] = $this->report($target);
            }
            $this->entityManager->flush();

            return $report;
        } finally {
            $lock->release();
        }
    }

    // The refusal email's "Send again", or the calendar panel's for $network alone: only the targets a network refused, a draft never reviewed staying a draft - null while a "Publish" of the same post is sending it
    /** @return ?array<string, array<string, mixed>> */
    public function retryFailed(SocialPost $post, ?string $network = null): ?array
    {
        return $this->sendTargets($post, null === $network ? $post->getNetworks() : [$network], SocialPostStatus::Failed, false);
    }

    // Sends the post's targets in $status on the given networks - null while a "Publish" of the same post is sending it
    /**
     * @param list<string> $networks
     *
     * @return ?array<string, array<string, mixed>>
     */
    private function sendTargets(SocialPost $post, array $networks, SocialPostStatus $status, bool $dryRun): ?array
    {
        $lock = $this->lockFactory->createLock('social_post_publish_' . $post->getId());
        if (!$lock->acquire()) {
            return null;
        }

        try {
            // What a "Publish" finished just before has saved, the same as publish() does
            foreach ($post->getTargets() as $target) {
                $this->entityManager->refresh($target);
            }

            $content = $this->contentOf($post);
            $all = $this->allNetworks();

            $report = [];
            foreach ($post->getTargets() as $target) {
                if (!\in_array($target->getNetwork(), $networks, true)) {
                    continue;
                }

                $network = $all[$target->getNetwork()] ?? null;
                // A post gone out whose comment, reshare or story a network refused: those alone are sent again
                if (!$dryRun && SocialPostStatus::Failed === $status && $target->isFollowUpPending()) {
                    $this->sendFollowUps($network, $target, $content);
                    $this->entityManager->flush();
                    $report[$target->getNetwork()] = $this->report($target);
                    continue;
                }
                if ($status !== $target->getStatus()) {
                    continue;
                }

                if ($dryRun) {
                    $report[$target->getNetwork()] = $this->previewTarget($network, $target, $content);
                    continue;
                }

                // Saved after each network, so a process killed later never sends again what already went out
                $this->send($network, $target, $content);
                $this->entityManager->flush();
                $report[$target->getNetwork()] = $this->report($target);
            }

            if (!$dryRun) {
                $this->entityManager->flush();
            }

            return $report;
        } finally {
            $lock->release();
        }
    }

    // A post removed, false while a "Publish" is sending it: what goes out then would leave no record. $keepGoneOut also keeps one that "Publish" just saved as gone out
    public function delete(SocialPost $post, bool $keepGoneOut = false): bool
    {
        $lock = $this->lockFactory->createLock('social_post_publish_' . $post->getId());
        if (!$lock->acquire()) {
            return false;
        }

        try {
            foreach ($post->getTargets() as $target) {
                $this->entityManager->refresh($target);
            }
            if ($keepGoneOut && $post->hasGoneOut()) {
                return false;
            }

            $this->entityManager->remove($post);
            $this->entityManager->flush();

            return true;
        } finally {
            $lock->release();
        }
    }

    // A post written on its screen, planned at $at and ticked on every network connected but those of videos alone, ticked once a video is added - one empty text each, written from the post's text once it is saved (see rewriteTargets())
    public function createManual(\DateTimeImmutable $at): SocialPost
    {
        $post = new SocialPost(SocialPost::SOURCE_MANUAL, bin2hex(random_bytes(8)), '', '', null, $at);
        foreach (array_keys($this->withoutVideoOnly($this->networksByName())) as $network) {
            new SocialPostTarget($post, $network, '');
        }

        return $post;
    }

    // Whether $sources (see prepareDrafts()) ask a source choosing by date, whose empty day says nothing of the days after it
    /** @param list<string> $sources */
    public function hasDatedSource(array $sources): bool
    {
        $scopes = $this->scopesOf($sources);

        return array_any(iterator_to_array($this->sources, false), static fn (SocialContentSourceInterface $source): bool => $source instanceof DatedSocialContentSourceInterface && ([] === $scopes || isset($scopes[$source->getSourceType()])));
    }

    // A post planned at $at from the next content of $sources (see prepareDrafts()), with no network yet: its text and its networks are its caller's - null when no network is connected, the sources ran out or hold nothing for $at
    /** @param list<string> $sources */
    public function createFromSources(\DateTimeImmutable $at, array $sources): ?SocialPost
    {
        [$sourceType, $content] = $this->hasConnectedNetwork() ? $this->nextContent($this->scopesOf($sources), $at) : ['', null];

        return null === $content ? null : new SocialPost($sourceType, $content->sourceId, $content->title, $content->url, $content->imageUrl, $at)->setLocale($content->locale);
    }

    // One content of an installed source, read again - null for a source not installed here or a content gone
    public function findContent(string $sourceType, string $sourceId): ?SocialContent
    {
        foreach ($this->sources as $source) {
            if ($source->getSourceType() === $sourceType) {
                return $source->getContent($sourceId);
            }
        }

        return null;
    }

    // Whether a content is held by a post still to come outside a series - what an imported draft cannot take, the posts of its own file sharing it at will and a content gone out being posted again when a person chose it
    public function isHeldOutside(string $sourceType, string $sourceId, ?SocialSeries $series): bool
    {
        $now = new \DateTimeImmutable();

        return array_any($this->postRepository->findBySourceIds($sourceType, [$sourceId]), static fn (SocialPost $post): bool => (null === $series || $post->getSeries() !== $series) && $post->getPlannedAt() > $now && !$post->hasGoneOut());
    }

    // Whether a content is still free: held by no post, nor gone out within its source's repeat delay - $except, the post asking, not counting when it holds it itself. A source not installed here has nothing free
    public function isFree(string $sourceType, string $sourceId, ?SocialPost $except = null): bool
    {
        if (null !== $except && $except->getSourceType() === $sourceType && $except->getSourceId() === $sourceId) {
            return true;
        }

        foreach ($this->sources as $source) {
            if ($source->getSourceType() === $sourceType) {
                return !\in_array($sourceId, $this->excludedIds($source), true);
            }
        }

        return false;
    }

    // The contents still free in each source, or in the ones named: up to $limit of each for a source that lists them, its next one alone for a source that only hands them out one by one - what a post written elsewhere is chosen among (see c975l:social:contents)
    /**
     * @param list<string> $sourceTypes
     *
     * @return list<array{source: string, scope: ?string, content: SocialContent}>
     */
    public function freeContents(array $sourceTypes, int $limit): array
    {
        $free = [];
        foreach ($this->sources as $source) {
            $type = $source->getSourceType();
            if ([] !== $sourceTypes && !\in_array($type, $sourceTypes, true)) {
                continue;
            }

            $excludedIds = $this->excludedIds($source);
            $contents = $source instanceof BrowsableSocialContentSourceInterface ? $source->findContents($excludedIds, [], $limit) : array_filter([$source->getNextContent($excludedIds)]);
            foreach ($contents as $content) {
                $scope = $source instanceof BrowsableSocialContentSourceInterface ? $source->getContentScope($content->sourceId) : null;
                $free[] = ['source' => $type, 'scope' => $scope, 'content' => $content];
            }
        }

        return $free;
    }

    // The source of a post whose content may be changed on its screen, null for one written there, read from a page, gone out already, or whose source lists nothing
    public function browsableSourceOf(SocialPost $post): ?BrowsableSocialContentSourceInterface
    {
        if ($post->hasGoneOut()) {
            return null;
        }

        foreach ($this->sources as $source) {
            if ($source instanceof BrowsableSocialContentSourceInterface && $source->getSourceType() === $post->getSourceType()) {
                return $source;
            }
        }

        return null;
    }

    // The contents a post may change to, still free, within $scope - an empty one meaning every group
    /** @return list<SocialContent> */
    public function contentChoices(SocialPost $post, string $scope, int $limit): array
    {
        $source = $this->browsableSourceOf($post);

        return $source?->findContents($this->excludedIds($source), '' === $scope ? [] : [$scope], $limit) ?? [];
    }

    // Another content drawn for the post in the group of the one it holds, or for its planned date from a dated source, its texts written again from it - false when none is left there
    public function redraw(SocialPost $post): bool
    {
        $source = $this->browsableSourceOf($post);
        if (null === $source) {
            return false;
        }

        $scope = $source->getContentScope($post->getSourceId());

        return $this->switchContent($post, $this->askSource($source, $this->excludedIds($source), null === $scope ? [] : [$scope], $post->getPlannedAt()));
    }

    // The content chosen for the post, only among those still free, its texts written again from it - an id typed by hand, or taken meanwhile, changing nothing
    public function changeContent(SocialPost $post, string $sourceId): bool
    {
        $source = $this->browsableSourceOf($post);
        if (null === $source || \in_array($sourceId, $this->excludedIds($source), true)) {
            return false;
        }

        return $this->switchContent($post, $source->getContent($sourceId));
    }

    // The post moved to $content, every text not out yet written from it the way addTargets() writes them - a post with its own text keeps it, only its image changing
    private function switchContent(SocialPost $post, ?SocialContent $content): bool
    {
        if (null === $content || !$post->changeContent($content)) {
            return false;
        }

        if ($post->hasOwnText()) {
            return true;
        }

        $maxLengths = [];
        foreach ($post->getTargets() as $target) {
            $maxLength = $this->getMaxLength($target->getNetwork());
            if ($target->isPending() && null !== $maxLength) {
                $maxLengths[$target->getNetwork()] = $maxLength;
            }
        }

        $written = $this->writer->write($content, $maxLengths);
        foreach ($post->getTargets() as $target) {
            if (isset($maxLengths[$target->getNetwork()])) {
                $target->setText($written[$target->getNetwork()] ?? $this->textBuilder->build($content, $maxLengths[$target->getNetwork()]));
            }
        }

        return true;
    }

    // Writes a text for each network ticked on a post that had none, the way prepare() does - approved along with the rest of the post when it was; a post written on its screen gets its own text, cut to each network. A network not connected gets no text: it could not send it. A repost unticked comes back a repost
    /** @param list<string> $networks */
    public function addTargets(SocialPost $post, array $networks): void
    {
        $content = $this->contentOf($post);
        $targets = array_intersect_key($this->networksByName(), array_flip($networks));
        if (null === $content || [] === $targets) {
            return;
        }

        $written = $post->hasOwnText() ? [] : $this->writer->write($content, array_map(static fn (NetworkPublisherInterface $network): int => $network->getMaxLength(), $targets));
        foreach ($targets as $name => $network) {
            $text = $post->hasOwnText() ? $this->textBuilder->cut((string) $post->getText(), $network->getMaxLength()) : ($written[$name] ?? $this->textBuilder->build($content, $network->getMaxLength()));
            $target = new SocialPostTarget($post, $name, $text);
            $target->setRepost($post->takeDroppedRepost($name));
            // A network asking choices of its own waits for them to be made, approved from the post's screen
            if ($post->isApproved() && !$network instanceof PostOptionsPublisherInterface) {
                $target->approve();
            }
        }
    }

    // A post written on its screen whose text changed: every text not out yet written from it again, cut to its network - a text corrected on its own stays as long as the post's text does not change
    public function rewriteTargets(SocialPost $post): void
    {
        if (!$post->hasOwnText() || !$post->takeTextChanged()) {
            return;
        }

        foreach ($post->getTargets() as $target) {
            $maxLength = $this->getMaxLength($target->getNetwork());
            if ($target->isPending() && null !== $maxLength) {
                $target->setText($this->textBuilder->cut((string) $post->getText(), $maxLength));
            }
        }
    }

    // What a configured network takes with a post, null for one this site does not post to
    public function getMediaRules(string $network): ?MediaRules
    {
        return ($this->networksByName()[$network] ?? null)?->getMediaRules();
    }

    // The longest text a configured network accepts, null for one this site does not post to - what the post's screen tells whoever edits its text
    public function getMaxLength(string $network): ?int
    {
        return ($this->networksByName()[$network] ?? null)?->getMaxLength();
    }

    // Every post is prepared as a draft planned at $plannedAt, to be read and approved - a dry run, which saves nothing, at once
    /** @return array<string, array<string, mixed>> */
    private function prepare(string $sourceType, SocialContent $content, bool $dryRun, \DateTimeImmutable $plannedAt): array
    {
        $post = new SocialPost($sourceType, $content->sourceId, $content->title, $content->url, $content->imageUrl, $plannedAt)->setLocale($content->locale);
        $this->prepared = null;

        // Every network on a dry run, the configured ones only otherwise: the dry run is what is read before any credential is plugged in. A content brings no video, so a network of videos alone is left out
        $targets = $dryRun ? $this->allNetworks() : $this->withoutVideoOnly($this->networksByName());
        // Written by the site's AI in one call where it has one, the template writing whatever it left out - never on a dry run, which costs nothing and shows the template's text
        $written = $dryRun ? [] : $this->writer->write($content, array_map(static fn (NetworkPublisherInterface $network): int => $network->getMaxLength(), $targets));

        $report = [];
        foreach ($targets as $name => $network) {
            $text = $written[$name] ?? $this->textBuilder->build($content, $network->getMaxLength());
            if ($dryRun) {
                $report[$name] = $this->preview($network, $text, $content);
                continue;
            }

            $report[$name] = $this->report(new SocialPostTarget($post, $name, $text));
        }

        // No network configured prepares nothing: a post with no target would still take the content, and it would never be offered again
        if (!$dryRun && [] !== $report) {
            $this->entityManager->persist($post);
            $this->entityManager->flush();
            $this->prepared = $post;
        }

        return $report;
    }

    // The next content, asked of the source that had a post least recently first, so a site with photos and stories alternates between them, a source with nothing for $at leaving the moment to the next one. At equal recency a dated source goes first, what it has being good for this date alone. $scopes narrows it to some sources (see scopesOf())
    /**
     * @param array<string, list<string>> $scopes
     *
     * @return array{0: string, 1: ?SocialContent}
     */
    private function nextContent(array $scopes = [], ?\DateTimeImmutable $at = null): array
    {
        $lastCreated = $this->postRepository->findLastCreatedAtBySourceType();
        $sources = iterator_to_array($this->sources, false);
        usort($sources, static fn (SocialContentSourceInterface $a, SocialContentSourceInterface $b): int => [$lastCreated[$a->getSourceType()] ?? '', $b instanceof DatedSocialContentSourceInterface] <=> [$lastCreated[$b->getSourceType()] ?? '', $a instanceof DatedSocialContentSourceInterface]);

        foreach ($sources as $source) {
            $type = $source->getSourceType();
            if ([] !== $scopes && !isset($scopes[$type])) {
                continue;
            }

            $content = $this->askSource($source, $this->excludedIds($source), $scopes[$type] ?? [], $at);
            if (null !== $content) {
                return [$source->getSourceType(), $content];
            }
        }

        return ['', null];
    }

    // What a source hands out: for $at when it chooses by date, its contract taking no group, otherwise within its groups picked
    /**
     * @param list<string> $excludedIds
     * @param list<string> $scopeIds
     */
    private function askSource(SocialContentSourceInterface $source, array $excludedIds, array $scopeIds, ?\DateTimeImmutable $at): ?SocialContent
    {
        if (null !== $at && $source instanceof DatedSocialContentSourceInterface) {
            return $source->getContentForDate($at, $excludedIds);
        }

        return $source instanceof ScopedSocialContentSourceInterface && [] !== $scopeIds
            ? $source->getNextScopedContent($excludedIds, $scopeIds)
            : $source->getNextContent($excludedIds);
    }

    // The ids of a source a post holds, the ones past its repeat delay offered again
    /** @return list<string> */
    private function excludedIds(SocialContentSourceInterface $source): array
    {
        $repeatAfterDays = $source->getRepeatAfterDays();
        $since = null === $repeatAfterDays ? null : new \DateTimeImmutable(sprintf('-%d days', $repeatAfterDays));

        return $this->postRepository->findSourceIds($source->getSourceType(), $since);
    }

    // The sources picked as the sources read them: the scope ids of each source type, an empty list for a whole source - none at all for every source. A whole source picked beside some of its groups takes it whole
    /**
     * @param list<string> $sources
     *
     * @return array<string, list<string>>
     */
    private function scopesOf(array $sources): array
    {
        $scopes = [];
        foreach ($sources as $source) {
            [$type, $scope] = explode(':', $source, 2) + [1 => null];
            $scopes[$type] ??= [];
            if (null !== $scope && !\in_array($type, $sources, true)) {
                $scopes[$type][] = $scope;
            }
        }

        return $scopes;
    }

    // The content as its source has it now, a post reviewed a day later going out with the image where it then is - the post's own copy for one read from a page, or whose source is no longer installed. Null once the source says the content is gone
    private function contentOf(SocialPost $post): ?SocialContent
    {
        foreach ($this->sources as $source) {
            if ($source->getSourceType() === $post->getSourceType()) {
                return $source->getContent($post->getSourceId());
            }
        }

        return new SocialContent($post->getSourceId(), $post->getTitle(), $post->getUrl(), imageUrl: $post->getImageUrl());
    }

    // The post's own pictures and videos, in their order, as the networks read them: on disk, and at their public address
    /** @return list<PostMedia> */
    private function mediasOf(SocialPost $post): array
    {
        $siteUrl = (string) $this->siteUrlResolver->siteUrl();
        $medias = [];
        foreach ($post->getMedias() as $media) {
            $path = $media->getPublicPath();
            if (null !== $path && null !== $media->getMimeType()) {
                $medias[] = new PostMedia($this->projectDir . '/public' . $path, $siteUrl . $path, $media->getMimeType(), $media->getSize(), $media->getWidth(), $media->getHeight(), $media->getDuration(), $media->getAlt());
            }
        }

        return $medias;
    }

    private function send(?NetworkPublisherInterface $network, SocialPostTarget $target, ?SocialContent $content): void
    {
        if (null === $network || !$network->isConfigured()) {
            $target->markFailed(sprintf('The network "%s" is not configured on this site.', $target->getNetwork()));

            return;
        }

        if (null === $content) {
            $target->markFailed('The content of this post no longer exists.');

            return;
        }

        try {
            $target->markPublished($this->deliver($network, $target, $content));
        } catch (\Throwable $exception) {
            // One network refusing never keeps the others from posting - the refusal is kept on the target, where "Publish" tries it again
            $this->logger->error('Social publication failed on {network}: {message}', ['network' => $target->getNetwork(), 'message' => $exception->getMessage()]);
            $target->markFailed($exception->getMessage());

            return;
        }

        $this->sendFollowUps($network, $target, $content);
    }

    // A repost when the target names an account, its post otherwise - the network's id for it either way
    private function deliver(NetworkPublisherInterface $network, SocialPostTarget $target, SocialContent $content): string
    {
        if ($network instanceof RepostPublisherInterface && null !== $target->getRepost()) {
            return $network->repost($target->getRepost(), (string) $target->getPost()->getUrl());
        }

        return $this->optioned($network, $target)->publish($target->getText(), $this->sentContent($network, $target, $content), $this->mediasOf($target->getPost()));
    }

    // What follows a post gone out, each refusal kept on the target without touching the post: its first comment, its reshare on the member's profile, then its story
    private function sendFollowUps(?NetworkPublisherInterface $network, SocialPostTarget $target, ?SocialContent $content): void
    {
        if ($target->isCommentPending()) {
            $this->sendComment($network, $target);
        }
        if ($target->isResharePending()) {
            $this->sendReshare($network, $target);
        }
        if ($target->isStoryPending()) {
            $this->sendStory($network, $target, $content);
        }
    }

    // The content as the network gets it: in the post's language, which may have been corrected, and without its link when a first comment carries it, so the post goes out as its picture rather than a link card
    private function sentContent(NetworkPublisherInterface $network, SocialPostTarget $target, SocialContent $content): SocialContent
    {
        $commented = null !== $target->getComment() && $network instanceof FirstCommentPublisherInterface;

        return new SocialContent($content->sourceId, $content->title, $commented ? '' : $content->url, $content->imagePath, $content->imageUrl, $content->imageAlt, $content->variables, $target->getPost()->getLocale() ?? $content->locale);
    }

    // The first comment under a post gone out, its refusal kept on the target without touching the post
    private function sendComment(?NetworkPublisherInterface $network, SocialPostTarget $target): void
    {
        // A comment whose network is no longer connected is said refused, so the retry screen gives a reason and tries again once it is
        if (null === $network && null !== $target->getComment()) {
            $target->markCommentFailed('The network is no longer connected.');

            return;
        }

        // A post accepted without its id (LinkedIn's missing urn) has nothing to comment on
        if (!$network instanceof FirstCommentPublisherInterface || '' === (string) $target->getExternalId() || null === $target->getComment()) {
            return;
        }

        try {
            $target->markCommented($network->comment($target->getExternalId(), $target->getComment()));
        } catch (\Throwable $exception) {
            $this->logger->error('Social first comment failed on {network}: {message}', ['network' => $target->getNetwork(), 'message' => $exception->getMessage()]);
            $target->markCommentFailed($exception->getMessage());
        }
    }

    // The post shared again on the member's profile, said refused when the network no longer posts as a Page, so the retry screen gives a reason
    private function sendReshare(?NetworkPublisherInterface $network, SocialPostTarget $target): void
    {
        if (!$network instanceof ProfileResharePublisherInterface || !$network->canReshare()) {
            $target->markReshareFailed('The network no longer posts as a Page.');

            return;
        }
        if ('' === (string) $target->getExternalId()) {
            $target->markReshareFailed('The network did not answer the id of the post, which cannot be reshared.');

            return;
        }

        try {
            $target->markReshared($network->reshare((string) $target->getExternalId(), (string) $target->getReshare()));
        } catch (\Throwable $exception) {
            $this->logger->error('Social reshare failed on {network}: {message}', ['network' => $target->getNetwork(), 'message' => $exception->getMessage()]);
            $target->markReshareFailed($exception->getMessage());
        }
    }

    // The post's video as a story when it has one, its picture otherwise: its first own picture, the content's image failing that
    private function sendStory(?NetworkPublisherInterface $network, SocialPostTarget $target, ?SocialContent $content): void
    {
        if (!$network instanceof StoryPublisherInterface || null === $content) {
            $target->markStoryFailed(null === $content ? 'The content of this post no longer exists.' : 'The network is no longer connected.');

            return;
        }

        // A post with neither video nor picture can never make a story, so the box is unticked rather than failing on every retry, its reason kept
        $video = $this->storyVideo($target);
        $storyContent = $this->storyContent($target, $content);
        if (null === $video && null === $storyContent->imagePath && null === $storyContent->imageUrl) {
            $target->markStoryFailed('A story takes a picture, and this post has none.');
            $target->setStory(false);

            return;
        }

        // The site's default image says nothing of the post: no story rather than its logo, unticked without a refusal to report
        if (null === $video && SocialImageExporter::isDefaultImage($storyContent)) {
            $target->setStory(false);

            return;
        }

        try {
            $target->markStoried($network->story($storyContent, $video));
        } catch (\Throwable $exception) {
            $this->logger->error('Social story failed on {network}: {message}', ['network' => $target->getNetwork(), 'message' => $exception->getMessage()]);
            $target->markStoryFailed($exception->getMessage());
        }
    }

    // The post's first own video, the one a story shows before any picture
    private function storyVideo(SocialPostTarget $target): ?PostMedia
    {
        foreach ($this->mediasOf($target->getPost()) as $media) {
            if ($media->isVideo()) {
                return $media;
            }
        }

        return null;
    }

    // The content whose image the story shows, the post's first own picture when it has one
    private function storyContent(SocialPostTarget $target, SocialContent $content): SocialContent
    {
        foreach ($this->mediasOf($target->getPost()) as $media) {
            if (!$media->isVideo()) {
                return new SocialContent($content->sourceId, $content->title, $content->url, imagePath: $media->path, imageAlt: $media->alt);
            }
        }

        return $content;
    }

    // A target's dry run, the first comment it would be answered with beside the post - or why it would not go, nothing to send it with
    /** @return array<string, mixed> */
    private function previewTarget(?NetworkPublisherInterface $network, SocialPostTarget $target, ?SocialContent $content): array
    {
        if (null === $network || null === $content) {
            return ['status' => self::DRY_RUN, 'message' => $target->getStatus()->value, 'payload' => ['error' => 'Nothing to send it with.']];
        }
        if (null !== $target->getRepost()) {
            return ['status' => self::DRY_RUN, 'message' => $this->mode($network), 'payload' => ['repost' => $target->getRepost(), 'url' => $target->getPost()->getUrl()]];
        }

        $preview = $this->preview($this->optioned($network, $target), $target->getText(), $this->sentContent($network, $target, $content), $this->mediasOf($target->getPost()));
        if ($network instanceof FirstCommentPublisherInterface && null !== $target->getComment()) {
            $preview['payload']['first_comment'] = $target->getComment();
        }
        if (null !== $target->getReshare() && $this->supportsReshare($target->getNetwork())) {
            $preview['payload']['profile_reshare'] = $target->getReshare();
        }
        if ($target->isStory() && $network instanceof StoryPublisherInterface) {
            $preview['payload']['story'] = true;
        }

        return $preview;
    }

    // Whether a network answers its posts with a first comment, which the post's screen then offers
    public function supportsComment(string $network): bool
    {
        return ($this->allNetworks()[$network] ?? null) instanceof FirstCommentPublisherInterface;
    }

    // Whether a network shows the post as a story too, which the post's screen then offers
    public function supportsStory(string $network): bool
    {
        return ($this->allNetworks()[$network] ?? null) instanceof StoryPublisherInterface;
    }

    // The form type of the choices a network asks post by post, which the post's screen then offers - null for one asking none
    public function getOptionsType(string $network): ?string
    {
        $publisher = $this->allNetworks()[$network] ?? null;

        return $publisher instanceof PostOptionsPublisherInterface ? $publisher->getOptionsType() : null;
    }

    // What keeps a target from being approved with the choices made on it, empty on a network asking none - $live asking the network too
    /** @return list<TranslatableMessage> */
    public function checkOptions(SocialPostTarget $target, bool $live = false): array
    {
        $publisher = $this->networksByName()[$target->getNetwork()] ?? null;

        return $publisher instanceof PostOptionsPublisherInterface ? $publisher->checkOptions($target->getOptions(), $target->getPost(), $live) : [];
    }

    // The network publishing with the choices made on the target, as it is on a network asking none
    private function optioned(NetworkPublisherInterface $network, SocialPostTarget $target): NetworkPublisherInterface
    {
        return $network instanceof PostOptionsPublisherInterface ? $network->withOptions($target->getOptions()) : $network;
    }

    // Whether a network can repost another account's post with the connection it holds
    public function supportsRepost(string $network): bool
    {
        $publisher = $this->allNetworks()[$network] ?? null;

        return $publisher instanceof RepostPublisherInterface && $publisher->canRepost();
    }

    // Whether a network posts as a Page it can share again on the member's profile, which the post's screen then offers
    public function supportsReshare(string $network): bool
    {
        $publisher = $this->allNetworks()[$network] ?? null;

        return $publisher instanceof ProfileResharePublisherInterface && $publisher->canReshare();
    }

    /**
     * @return array<string, mixed>
     */
    private function report(SocialPostTarget $target): array
    {
        return array_filter([
            'status' => $target->getStatus()->value,
            'message' => (string) ($target->getExternalId() ?? $target->getError()),
            'comment_error' => $target->getCommentError(),
            'reshare_error' => $target->getReshareError(),
            'story_error' => $target->getStoryError(),
        ], static fn (?string $value): bool => null !== $value);
    }

    // What a network would receive, or why it would refuse - Instagram taking no post without an image, one network saying so must not hide what the others would get
    /**
     * @param list<PostMedia> $medias
     *
     * @return array<string, mixed>
     */
    private function preview(NetworkPublisherInterface $network, string $text, SocialContent $content, array $medias = []): array
    {
        try {
            return ['status' => self::DRY_RUN, 'message' => $this->mode($network), 'payload' => $network->preview($text, $content, $medias)];
        } catch (\Throwable $exception) {
            return ['status' => self::DRY_RUN, 'message' => $this->mode($network), 'payload' => ['error' => $exception->getMessage()]];
        }
    }

    // The publication is on as soon as one network is connected, no switch to turn on besides
    public function hasConnectedNetwork(): bool
    {
        return [] !== $this->networksByName();
    }

    // How a dry run labels a network: whether it could go out at all yet
    private function mode(NetworkPublisherInterface $network): string
    {
        return $network->isConfigured() ? 'configured' : 'not configured';
    }

    // The configured networks, by name - an unconfigured one gets no target at all
    /** @return array<string, NetworkPublisherInterface> */
    private function networksByName(): array
    {
        return array_filter($this->allNetworks(), static fn (NetworkPublisherInterface $network): bool => $network->isConfigured());
    }

    // The networks a post may go to without a video of its own
    /**
     * @param array<string, NetworkPublisherInterface> $networks
     *
     * @return array<string, NetworkPublisherInterface>
     */
    private function withoutVideoOnly(array $networks): array
    {
        return array_filter($networks, static fn (NetworkPublisherInterface $network): bool => !$network->getMediaRules()->videoRequired);
    }

    /**
     * @return array<string, NetworkPublisherInterface>
     */
    private function allNetworks(): array
    {
        $networks = [];
        foreach ($this->networks as $network) {
            $networks[$network->getName()] = $network;
        }

        return $networks;
    }
}
