<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Contract\NetworkPublisherInterface;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Entity\SocialSchedule;
use c975L\SocialBundle\Enum\SocialPostStatus;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Repository\SocialScheduleRepository;
use c975L\UiBundle\Contract\ScopedSocialContentSourceInterface;
use c975L\UiBundle\Contract\SocialContentSourceInterface;
use c975L\UiBundle\Model\SocialContent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

// The publication on the site's networks: prepares a post - one target per configured network, each with its own text - then sends at once the targets of the networks set to publish automatically, the others waiting as drafts for the screen's "Publish". Every method answers the same report, keyed by network: ['status' => a SocialPostStatus value or "dry_run", 'message' => the post's id there or the network's refusal, 'payload' => what would be sent, on a dry run only]
class SocialPublisher
{
    public const string DRY_RUN = 'dry_run';

    // The run is scheduled hourly on a fixed minute, so a post prepared at 10:37 is checked again at 10:37 the next day, a few seconds short of 24 hours - without this margin, each post would slip an hour later than the one before
    private const int INTERVAL_MARGIN = 600;

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
        private readonly ConfigServiceInterface $configService,
        private readonly LoggerInterface $logger,
        private readonly LockFactory $lockFactory,
        private readonly SocialScheduleRepository $scheduleRepository,
        private readonly SocialPlanner $planner,
        private readonly SocialPostWriter $writer,
    ) {
    }

    // The scheduled run: once the interval has passed, prepares the next content no post took yet - [] when nothing was due, configured or left to post. Standing aside while a publication slot is enabled, the slots then being the pace
    /** @return array<string, array<string, mixed>> */
    public function prepareNext(bool $force = false, bool $dryRun = false): array
    {
        if (!$force && ($this->scheduleRepository->hasEnabled() || !$this->isDue($dryRun))) {
            return [];
        }

        [$sourceType, $content] = $this->nextContent();

        return null === $content ? [] : $this->prepare($sourceType, $content, $dryRun);
    }

    // A publication slot's run: the first unplanned approved post waiting on its networks, sent on those alone - or, with none waiting, the next content of its sources not posted yet on them. [] when none of its networks is connected, a planned post takes its place or nothing is left
    /** @return array<string, array<string, mixed>> */
    public function prepareSlot(SocialSchedule $slot, bool $dryRun = false): array
    {
        $networks = $slot->resolveNetworks(array_keys($dryRun ? $this->allNetworks() : $this->networksByName()));
        if ([] === $networks) {
            return [];
        }

        // A post planned on this slot's quarter of an hour stands in its place, whether the planned run sent it already or not
        $time = $slot->getTime();
        $moment = null === $time ? new \DateTimeImmutable() : new \DateTimeImmutable('today')->setTime((int) $time->format('G'), (int) $time->format('i'));
        if ($this->planner->isTaken($this->postRepository->findPlannedBetween($moment, $moment->modify('+' . SocialPlanner::QUARTER . ' seconds')), $networks, $moment)) {
            return [];
        }

        $approved = $this->planner->pick($this->postRepository->findApproved(), $networks);
        if (null !== $approved) {
            return $this->sendApproved($approved, $networks, $dryRun) ?? [];
        }

        [$sourceType, $content] = $this->nextContent($slot->getScopesBySourceType(), $networks);

        return null === $content ? [] : $this->prepare($sourceType, $content, $dryRun, $networks, ['slot' => (string) $slot->getText()]);
    }

    // What a slot may pick among: each source whole, then each of its groups, labels keyed by the value SocialSchedule::$sources stores
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

    // The networks connected on this site, what a slot naming none of its own posts on
    /** @return list<string> */
    public function getConnectedNetworkNames(): array
    {
        return array_keys($this->networksByName());
    }

    // Every network a slot may post on, configured or not yet
    /** @return list<string> */
    public function getNetworkNames(): array
    {
        return array_keys($this->allNetworks());
    }

    // The calendar's "Prepare for this slot": the slot's next content, left as a draft planned for that moment rather than sent - null when the slot has no network connected or nothing left
    public function prepareForSlot(SocialSchedule $slot, \DateTimeImmutable $at): ?SocialPost
    {
        $networks = $slot->resolveNetworks($this->getConnectedNetworkNames());
        if ([] === $networks) {
            return null;
        }

        [$sourceType, $content] = $this->nextContent($slot->getScopesBySourceType(), $networks);
        if (null === $content) {
            return null;
        }

        $this->prepare($sourceType, $content, false, $networks, ['slot' => (string) $slot->getText()], $at, false);

        return $this->prepared;
    }

    // The planned run, every quarter of an hour: every approved post whose moment has come, sent on all its approved networks, keyed "#<post id> <network>"
    /** @return array<string, array<string, mixed>> */
    public function publishPlanned(bool $dryRun = false): array
    {
        $now = new \DateTimeImmutable();
        $report = [];
        foreach ($this->postRepository->findApproved() as $post) {
            $planned = $post->getPlannedAt();
            if (null === $planned || $planned > $now) {
                continue;
            }

            foreach ($this->sendApproved($post, $post->getApprovedNetworks(), $dryRun) ?? [] as $network => $result) {
                $report['#' . $post->getId() . ' ' . $network] = $result;
            }
        }

        return $report;
    }

    // Several posts prepared at once, as drafts to approve one after the other - never sent at once, even on a network set to publish automatically. Fewer when the contents run out, none while no network is connected
    /** @return list<SocialPost> */
    public function prepareDrafts(int $count): array
    {
        $posts = [];
        while (\count($posts) < $count && $this->hasConnectedNetwork()) {
            [$sourceType, $content] = $this->nextContent();
            if (null === $content) {
                break;
            }

            $this->prepare($sourceType, $content, false, send: false);
            if (null === $this->prepared) {
                break;
            }
            $posts[] = $this->prepared;
        }

        return $posts;
    }

    // Prepares a post of any page, read from its Open Graph tags, whatever the interval
    /** @return array<string, array<string, mixed>> */
    public function prepareUrl(string $url, bool $dryRun = false): array
    {
        // Its drafts would have no network to go out on
        if (!$dryRun && !$this->hasConnectedNetwork()) {
            throw new \RuntimeException('No network is connected yet: connect one on the "Connections" screen first.');
        }

        return $this->prepare(SocialPost::SOURCE_URL, $this->pageReader->read($url), $dryRun);
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

    // Sends the post's approved targets on the given networks, the others waiting for a slot posting on theirs - null while a "Publish" of the same post is sending it
    /**
     * @param list<string> $networks
     *
     * @return ?array<string, array<string, mixed>>
     */
    private function sendApproved(SocialPost $post, array $networks, bool $dryRun): ?array
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
                if (SocialPostStatus::Approved !== $target->getStatus() || !\in_array($target->getNetwork(), $networks, true)) {
                    continue;
                }

                $network = $all[$target->getNetwork()] ?? null;
                if ($dryRun) {
                    $report[$target->getNetwork()] = null === $network || null === $content
                        ? ['status' => self::DRY_RUN, 'message' => 'approved', 'payload' => ['error' => 'Nothing to send it with.']]
                        : $this->preview($network, $target->getText(), $content);
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

    // Writes a text for each network ticked on a post that had none, the way prepare() does - approved along with the rest of the post when it was. A network not connected gets no text: it could not send it
    /** @param list<string> $networks */
    public function addTargets(SocialPost $post, array $networks): void
    {
        $content = $this->contentOf($post);
        $targets = array_intersect_key($this->networksByName(), array_flip($networks));
        if (null === $content || [] === $targets) {
            return;
        }

        $written = $this->writer->write($content, array_map(static fn (NetworkPublisherInterface $network): int => $network->getMaxLength(), $targets));
        foreach ($targets as $name => $network) {
            $target = new SocialPostTarget($post, $name, $written[$name] ?? $this->textBuilder->build($content, $network->getMaxLength()));
            if ($post->isApproved()) {
                $target->approve();
            }
        }
    }

    // The longest text a configured network accepts, null for one this site does not post to - what the post's screen tells whoever edits its text
    public function getMaxLength(string $network): ?int
    {
        return ($this->networksByName()[$network] ?? null)?->getMaxLength();
    }

    // $networks narrows the post to a slot's networks, $variables adds the slot's own placeholders to the text
    /**
     * @param list<string>|null     $networks
     * @param array<string, string> $variables
     *
     * @return array<string, array<string, mixed>>
     */
    private function prepare(string $sourceType, SocialContent $content, bool $dryRun, ?array $networks = null, array $variables = [], ?\DateTimeImmutable $plannedAt = null, bool $send = true): array
    {
        $post = new SocialPost($sourceType, $content->sourceId, $content->title, $content->url, $content->imageUrl);
        $post->setPlannedAt($plannedAt);
        $this->prepared = null;

        // Every network on a dry run, the configured ones only otherwise: the dry run is what is read before any credential is plugged in
        $targets = array_filter($dryRun ? $this->allNetworks() : $this->networksByName(), static fn (NetworkPublisherInterface $network, string $name): bool => null === $networks || \in_array($name, $networks, true), \ARRAY_FILTER_USE_BOTH);
        // Written by the site's AI in one call where it has one, the template writing whatever it left out - never on a dry run, which costs nothing and shows the template's text
        $written = $dryRun ? [] : $this->writer->write($content, array_map(static fn (NetworkPublisherInterface $network): int => $network->getMaxLength(), $targets), $variables);

        $report = [];
        foreach ($targets as $name => $network) {
            $text = $written[$name] ?? $this->textBuilder->build($content, $network->getMaxLength(), $variables);
            if ($dryRun) {
                $report[$name] = $this->preview($network, $text, $content);
                continue;
            }

            $target = new SocialPostTarget($post, $name, $text);
            // A post prepared for a reading - planned for a slot, or one of a batch - waits on every network, an automatic one included
            if ($send && $network->isAutomatic()) {
                $this->send($network, $target, $content);
            }
            $report[$name] = $this->report($target);
        }

        // No network configured prepares nothing: a post with no target would still take the content, and it would never be offered again
        if (!$dryRun && [] !== $report) {
            $this->entityManager->persist($post);
            $this->entityManager->flush();
            $this->prepared = $post;
        }

        return $report;
    }

    // Whether a network is connected - a dry run being read before any is - and the interval since the last post prepared has passed
    private function isDue(bool $dryRun): bool
    {
        if (!$dryRun && !$this->hasConnectedNetwork()) {
            return false;
        }

        $last = $this->postRepository->findLastCreatedAt();
        $interval = max(1, (int) $this->configService->get('social-publish-interval-hours')) * 3600 - self::INTERVAL_MARGIN;

        return null === $last || time() - $last->getTimestamp() >= $interval;
    }

    // The next content, asked of the source that had a post least recently first, so a site with photos and stories alternates between them. $scopes narrows it to a slot's sources (see SocialSchedule::getScopesBySourceType()), $networks to what was not posted yet on those
    /**
     * @param array<string, list<string>> $scopes
     * @param list<string>                $networks
     *
     * @return array{0: string, 1: ?SocialContent}
     */
    private function nextContent(array $scopes = [], array $networks = []): array
    {
        $lastCreated = $this->postRepository->findLastCreatedAtBySourceType();
        $sources = iterator_to_array($this->sources, false);
        usort($sources, static fn (SocialContentSourceInterface $a, SocialContentSourceInterface $b): int => ($lastCreated[$a->getSourceType()] ?? '') <=> ($lastCreated[$b->getSourceType()] ?? ''));

        foreach ($sources as $source) {
            $type = $source->getSourceType();
            if ([] !== $scopes && !isset($scopes[$type])) {
                continue;
            }

            $repeatAfterDays = $source->getRepeatAfterDays();
            $since = null === $repeatAfterDays ? null : new \DateTimeImmutable(sprintf('-%d days', $repeatAfterDays));
            $excludedIds = $this->postRepository->findSourceIds($type, $since, $networks);
            $content = $source instanceof ScopedSocialContentSourceInterface && [] !== ($scopes[$type] ?? [])
                ? $source->getNextScopedContent($excludedIds, $scopes[$type])
                : $source->getNextContent($excludedIds);
            if (null !== $content) {
                return [$source->getSourceType(), $content];
            }
        }

        return ['', null];
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
            $target->markPublished($network->publish($target->getText(), $content));
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
    /** @return array<string, mixed> */
    private function preview(NetworkPublisherInterface $network, string $text, SocialContent $content): array
    {
        try {
            return ['status' => self::DRY_RUN, 'message' => $this->mode($network), 'payload' => $network->preview($text, $content)];
        } catch (\Throwable $exception) {
            return ['status' => self::DRY_RUN, 'message' => $this->mode($network), 'payload' => ['error' => $exception->getMessage()]];
        }
    }

    // Whether a publication slot is on, the slots being what sends an approved post - without one, "Publish" is the only way out
    public function hasEnabledSlot(): bool
    {
        return $this->scheduleRepository->hasEnabled();
    }

    // The publication is on as soon as one network is connected, no switch to turn on besides
    public function hasConnectedNetwork(): bool
    {
        return [] !== $this->networksByName();
    }

    // How a dry run labels a network: whether its post would wait for review, and whether it could go out at all yet
    private function mode(NetworkPublisherInterface $network): string
    {
        return ($network->isAutomatic() ? 'auto' : 'review') . ($network->isConfigured() ? '' : ', not configured');
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
