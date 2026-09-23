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
use c975L\SocialBundle\Repository\SocialPostRepository;
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
    ) {
    }

    // The scheduled run: once the interval has passed, prepares the next content no post took yet - [] when nothing was due, configured or left to post
    /** @return array<string, array<string, mixed>> */
    public function prepareNext(bool $force = false, bool $dryRun = false): array
    {
        if (!$force && !$this->isDue()) {
            return [];
        }

        [$sourceType, $content] = $this->nextContent();

        return null === $content ? [] : $this->prepare($sourceType, $content, $dryRun);
    }

    // Prepares a post of any page, read from its Open Graph tags, whatever the interval
    /** @return array<string, array<string, mixed>> */
    public function prepareUrl(string $url, bool $dryRun = false): array
    {
        // Its drafts would wait on a screen the menu does not show while the publication is off
        if (!$dryRun && !$this->isEnabled()) {
            throw new \RuntimeException('The publication is off: turn "social-publish-enabled" on first.');
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

    // The longest text a configured network accepts, null for one this site does not post to - what the post's screen tells whoever edits its text
    public function getMaxLength(string $network): ?int
    {
        return ($this->networksByName()[$network] ?? null)?->getMaxLength();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function prepare(string $sourceType, SocialContent $content, bool $dryRun): array
    {
        $post = new SocialPost($sourceType, $content->sourceId, $content->title, $content->url, $content->imageUrl);

        $report = [];
        // Every network on a dry run, the configured ones only otherwise: the dry run is what is read before any credential is plugged in
        foreach ($dryRun ? $this->allNetworks() : $this->networksByName() as $name => $network) {
            $text = $this->textBuilder->build($content, $network->getMaxLength());
            if ($dryRun) {
                $report[$name] = $this->preview($network, $text, $content);
                continue;
            }

            $target = new SocialPostTarget($post, $name, $text);
            if ($network->isAutomatic()) {
                $this->send($network, $target, $content);
            }
            $report[$name] = $this->report($target);
        }

        // No network configured prepares nothing: a post with no target would still take the content, and it would never be offered again
        if (!$dryRun && [] !== $report) {
            $this->entityManager->persist($post);
            $this->entityManager->flush();
        }

        return $report;
    }

    // Whether the feature is on and the interval since the last post prepared has passed
    private function isDue(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $last = $this->postRepository->findLastCreatedAt();
        $interval = max(1, (int) $this->configService->get('social-publish-interval-hours')) * 3600 - self::INTERVAL_MARGIN;

        return null === $last || time() - $last->getTimestamp() >= $interval;
    }

    // The next content, asked of the source that had a post least recently first, so a site with photos and stories alternates between them
    /** @return array{0: string, 1: ?SocialContent} */
    private function nextContent(): array
    {
        $lastCreated = $this->postRepository->findLastCreatedAtBySourceType();
        $sources = iterator_to_array($this->sources, false);
        usort($sources, static fn (SocialContentSourceInterface $a, SocialContentSourceInterface $b): int => ($lastCreated[$a->getSourceType()] ?? '') <=> ($lastCreated[$b->getSourceType()] ?? ''));

        foreach ($sources as $source) {
            $repeatAfterDays = $source->getRepeatAfterDays();
            $since = null === $repeatAfterDays ? null : new \DateTimeImmutable(sprintf('-%d days', $repeatAfterDays));
            $content = $source->getNextContent($this->postRepository->findSourceIds($source->getSourceType(), $since));
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

    private function isEnabled(): bool
    {
        return $this->configService->getBool($this->configService->get('social-publish-enabled'));
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
