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
use c975L\SocialBundle\Contract\NetworkPublisherInterface;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Enum\SocialPostStatus;
use c975L\SocialBundle\Model\MediaRules;
use c975L\SocialBundle\Model\PostMedia;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\UiBundle\Contract\BrowsableSocialContentSourceInterface;
use c975L\UiBundle\Contract\ScopedSocialContentSourceInterface;
use c975L\UiBundle\Contract\SocialContentSourceInterface;
use c975L\UiBundle\Model\SocialContent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

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

    // One draft per moment, the next content each time, $sources narrowing them to some sources or groups of them (the keys of getSourceChoices()) - never sent until approved. Fewer when the contents run out, none while no network is connected
    /**
     * @param list<\DateTimeImmutable> $moments
     * @param list<string>             $sources
     *
     * @return list<SocialPost>
     */
    public function prepareDrafts(array $moments, array $sources = []): array
    {
        $posts = [];
        foreach ($moments as $moment) {
            [$sourceType, $content] = $this->hasConnectedNetwork() ? $this->nextContent($this->scopesOf($sources)) : ['', null];
            if (null === $content) {
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
                }
                $report[$target->getNetwork()] = $this->report($target);
            }
            $this->entityManager->flush();

            return $report;
        } finally {
            $lock->release();
        }
    }

    // The refusal email's "Send again": only the targets a network refused, a draft never reviewed staying a draft - null while a "Publish" of the same post is sending it
    /** @return ?array<string, array<string, mixed>> */
    public function retryFailed(SocialPost $post): ?array
    {
        return $this->sendTargets($post, array_values(array_map(static fn (SocialPostTarget $target): string => $target->getNetwork(), $post->getTargets()->toArray())), SocialPostStatus::Failed, false);
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
                if ($status !== $target->getStatus() || !\in_array($target->getNetwork(), $networks, true)) {
                    continue;
                }

                $network = $all[$target->getNetwork()] ?? null;
                if ($dryRun) {
                    $report[$target->getNetwork()] = null === $network || null === $content
                        ? ['status' => self::DRY_RUN, 'message' => $status->value, 'payload' => ['error' => 'Nothing to send it with.']]
                        : $this->preview($network, $target->getText(), $content, $this->mediasOf($post));
                    continue;
                }

                $this->send($network, $target, $content);
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

    // A post written on its screen, planned at $at and ticked on every network connected - one empty text each, written from the post's text once it is saved (see rewriteTargets())
    public function createManual(\DateTimeImmutable $at): SocialPost
    {
        $post = new SocialPost(SocialPost::SOURCE_MANUAL, bin2hex(random_bytes(8)), '', '', null, $at);
        foreach ($this->getConnectedNetworkNames() as $network) {
            new SocialPostTarget($post, $network, '');
        }

        return $post;
    }

    // A post planned at $at from the next content of $sources (see prepareDrafts()), with no network yet: its text and its networks are its caller's - null when no network is connected or the sources ran out
    /** @param list<string> $sources */
    public function createFromSources(\DateTimeImmutable $at, array $sources): ?SocialPost
    {
        [$sourceType, $content] = $this->hasConnectedNetwork() ? $this->nextContent($this->scopesOf($sources)) : ['', null];

        return null === $content ? null : new SocialPost($sourceType, $content->sourceId, $content->title, $content->url, $content->imageUrl, $at);
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

    // Another content drawn for the post in the group of the one it holds, its texts written again from it - false when none is left there
    public function redraw(SocialPost $post): bool
    {
        $source = $this->browsableSourceOf($post);
        if (null === $source) {
            return false;
        }

        $scope = $source->getContentScope($post->getSourceId());
        $content = $source instanceof ScopedSocialContentSourceInterface && null !== $scope
            ? $source->getNextScopedContent($this->excludedIds($source), [$scope])
            : $source->getNextContent($this->excludedIds($source));

        return $this->switchContent($post, $content);
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

    // Writes a text for each network ticked on a post that had none, the way prepare() does - approved along with the rest of the post when it was; a post written on its screen gets its own text, cut to each network. A network not connected gets no text: it could not send it
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
            if ($post->isApproved()) {
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
        $post = new SocialPost($sourceType, $content->sourceId, $content->title, $content->url, $content->imageUrl, $plannedAt);
        $this->prepared = null;

        // Every network on a dry run, the configured ones only otherwise: the dry run is what is read before any credential is plugged in
        $targets = $dryRun ? $this->allNetworks() : $this->networksByName();
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

    // The next content, asked of the source that had a post least recently first, so a site with photos and stories alternates between them. $scopes narrows it to some sources (see scopesOf())
    /**
     * @param array<string, list<string>> $scopes
     *
     * @return array{0: string, 1: ?SocialContent}
     */
    private function nextContent(array $scopes = []): array
    {
        $lastCreated = $this->postRepository->findLastCreatedAtBySourceType();
        $sources = iterator_to_array($this->sources, false);
        usort($sources, static fn (SocialContentSourceInterface $a, SocialContentSourceInterface $b): int => ($lastCreated[$a->getSourceType()] ?? '') <=> ($lastCreated[$b->getSourceType()] ?? ''));

        foreach ($sources as $source) {
            $type = $source->getSourceType();
            if ([] !== $scopes && !isset($scopes[$type])) {
                continue;
            }

            $excludedIds = $this->excludedIds($source);
            $content = $source instanceof ScopedSocialContentSourceInterface && [] !== ($scopes[$type] ?? [])
                ? $source->getNextScopedContent($excludedIds, $scopes[$type])
                : $source->getNextContent($excludedIds);
            if (null !== $content) {
                return [$source->getSourceType(), $content];
            }
        }

        return ['', null];
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
            $target->markPublished($network->publish($target->getText(), $content, $this->mediasOf($target->getPost())));
        } catch (\Throwable $exception) {
            // One network refusing never keeps the others from posting - the refusal is kept on the target, where "Publish" tries it again
            $this->logger->error('Social publication failed on {network}: {message}', ['network' => $target->getNetwork(), 'message' => $exception->getMessage()]);
            $target->markFailed($exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function report(SocialPostTarget $target): array
    {
        return ['status' => $target->getStatus()->value, 'message' => (string) ($target->getExternalId() ?? $target->getError())];
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
