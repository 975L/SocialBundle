<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Contract\NetworkPublisherInterface;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Entity\SocialSchedule;
use c975L\SocialBundle\Enum\SocialPostStatus;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Repository\SocialScheduleRepository;
use c975L\SocialBundle\Service\SocialPageReader;
use c975L\SocialBundle\Service\SocialPostTextBuilder;
use c975L\SocialBundle\Service\SocialPublisher;
use c975L\UiBundle\Contract\ScopedSocialContentSourceInterface;
use c975L\UiBundle\Contract\SocialContentSourceInterface;
use c975L\UiBundle\Model\SocialContent;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

class SocialPublisherTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    /** @var array<string, array{excluded?: list<string>, since?: ?\DateTimeImmutable, networks?: list<string>, scopes?: list<string>}> */
    private array $asked = [];

    /** @var list<string> */
    private array $published = [];

    private ?LockFactory $lockFactory = null;

    private function createSource(string $type, ?string $nextId, ?int $repeatAfterDays = null, bool $gone = false): SocialContentSourceInterface
    {
        $source = $this->createStub(SocialContentSourceInterface::class);
        $source->method('getSourceType')->willReturn($type);
        $source->method('getRepeatAfterDays')->willReturn($repeatAfterDays);
        $source->method('getNextContent')->willReturnCallback(function (array $excludedIds) use ($type, $nextId): ?SocialContent {
            $this->asked[$type]['excluded'] = $excludedIds;

            return null === $nextId ? null : new SocialContent($nextId, 'Title ' . $nextId, 'https://example.org/' . $nextId);
        });
        $source->method('getContent')->willReturnCallback(static fn (string $id): ?SocialContent => $gone ? null : new SocialContent($id, 'Fresh', 'https://example.org/' . $id, imagePath: '/moved.webp'));

        return $source;
    }

    // A source whose contents fall into groups, the groups it was asked for being recorded
    /** @param array<string, string> $scopes */
    private function createScopedSource(string $type, string $nextId, array $scopes = ['3' => 'Mountain']): ScopedSocialContentSourceInterface
    {
        $source = $this->createStub(ScopedSocialContentSourceInterface::class);
        $source->method('getSourceType')->willReturn($type);
        $source->method('getScopes')->willReturn($scopes);
        $source->method('getNextContent')->willReturnCallback(function () use ($type, $nextId): SocialContent {
            $this->asked[$type]['scopes'] = [];

            return new SocialContent($nextId, 'Title ' . $nextId, 'https://example.org/' . $nextId);
        });
        $source->method('getNextScopedContent')->willReturnCallback(function (array $excludedIds, array $scopeIds) use ($type, $nextId): SocialContent {
            $this->asked[$type]['scopes'] = $scopeIds;

            return new SocialContent($nextId, 'Title ' . $nextId, 'https://example.org/' . $nextId);
        });

        return $source;
    }

    private function createNetwork(string $name, bool $automatic = true, bool $configured = true, ?\Throwable $failure = null): NetworkPublisherInterface
    {
        $network = $this->createStub(NetworkPublisherInterface::class);
        $network->method('getName')->willReturn($name);
        $network->method('isConfigured')->willReturn($configured);
        $network->method('isAutomatic')->willReturn($automatic);
        $network->method('getMaxLength')->willReturn(300);
        $network->method('preview')->willReturnCallback(static fn (string $text): array => ['text' => $text]);
        $network->method('publish')->willReturnCallback(function (string $text, SocialContent $content) use ($name, $failure): string {
            if (null !== $failure) {
                throw $failure;
            }
            $this->published[] = $name . ':' . ($content->imagePath ?? $content->imageUrl ?? '');

            return $name . '-post-id';
        });

        return $network;
    }

    /**
     * @param list<SocialContentSourceInterface> $sources
     * @param list<NetworkPublisherInterface>    $networks
     * @param array<string, string>              $lastBySourceType
     */
    private function createPublisher(array $sources, array $networks, bool $enabled = true, ?\DateTimeImmutable $last = null, array $lastBySourceType = [], string $page = '', bool $slotEnabled = false, ?string $template = null): SocialPublisher
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap([
            ['social-publish-enabled', $enabled ? 'true' : 'false'],
            ['social-publish-interval-hours', '24'],
            ['social-publish-template', $template],
        ]);
        $configService->method('getBool')->willReturnCallback(static fn ($value): bool => 'true' === $value);

        $repository = $this->createStub(SocialPostRepository::class);
        $repository->method('findLastCreatedAt')->willReturn($last);
        $repository->method('findLastCreatedAtBySourceType')->willReturn($lastBySourceType);
        $repository->method('findSourceIds')->willReturnCallback(function (string $type, ?\DateTimeImmutable $since, array $networks = []): array {
            $this->asked[$type]['since'] = $since;
            $this->asked[$type]['networks'] = $networks;

            return ['7'];
        });

        $scheduleRepository = $this->createStub(SocialScheduleRepository::class);
        $scheduleRepository->method('hasEnabled')->willReturn($slotEnabled);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        return new SocialPublisher(
            $sources,
            $networks,
            new SocialPostTextBuilder($configService),
            new SocialPageReader(new MockHttpClient(new MockResponse($page))),
            $repository,
            $entityManager,
            $configService,
            new NullLogger(),
            $this->lockFactory ??= new LockFactory(new InMemoryStore()),
            $scheduleRepository,
        );
    }

    private function preparedPost(): SocialPost
    {
        $this->assertCount(1, $this->persisted);
        $this->assertInstanceOf(SocialPost::class, $this->persisted[0]);

        return $this->persisted[0];
    }

    public function testAnAutomaticNetworkPostsAtOnceAndAReviewedOneWaits(): void
    {
        $publisher = $this->createPublisher([$this->createSource('gallery_media', '42')], [$this->createNetwork('bluesky'), $this->createNetwork('facebook', automatic: false)]);

        $report = $publisher->prepareNext();

        $this->assertSame(['status' => 'published', 'message' => 'bluesky-post-id'], $report['bluesky']);
        $this->assertSame(['status' => 'draft', 'message' => ''], $report['facebook']);
        $post = $this->preparedPost();
        $this->assertSame('42', $post->getSourceId());
        $this->assertCount(2, $post->getTargets());
        $this->assertSame(['7'], $this->asked['gallery_media']['excluded']);
    }

    public function testAnUnconfiguredNetworkGetsNoTarget(): void
    {
        $publisher = $this->createPublisher([$this->createSource('gallery_media', '42')], [$this->createNetwork('bluesky'), $this->createNetwork('other', configured: false)]);

        $this->assertSame(['bluesky'], array_keys($publisher->prepareNext()));
    }

    // A post with no target would still take its content, which would never be offered again
    public function testNothingIsPreparedWithoutAnyConfiguredNetwork(): void
    {
        $publisher = $this->createPublisher([$this->createSource('gallery_media', '42')], [$this->createNetwork('bluesky', configured: false)]);

        $this->assertSame([], $publisher->prepareNext());
        $this->assertSame([], $this->persisted);
    }

    public function testNothingIsPreparedWhileTheFeatureIsOff(): void
    {
        $publisher = $this->createPublisher([$this->createSource('gallery_media', '42')], [$this->createNetwork('bluesky')], enabled: false);

        $this->assertSame([], $publisher->prepareNext());
    }

    public function testNothingIsPreparedBeforeTheIntervalHasPassed(): void
    {
        $publisher = $this->createPublisher([$this->createSource('gallery_media', '42')], [$this->createNetwork('bluesky')], last: new \DateTimeImmutable('-2 hours'));

        $this->assertSame([], $publisher->prepareNext());
    }

    // The hourly run checks at a fixed minute, so a post prepared yesterday at the same time is a few seconds short of 24 hours - and the next one must still be prepared
    public function testTheNextPostIsPreparedAtTheSameTimeTheNextDay(): void
    {
        $publisher = $this->createPublisher([$this->createSource('gallery_media', '42')], [$this->createNetwork('bluesky')], last: new \DateTimeImmutable('-24 hours +30 seconds'));

        $this->assertCount(1, $publisher->prepareNext());
    }

    public function testForcePreparesWhateverTheIntervalAndTheSwitch(): void
    {
        $publisher = $this->createPublisher([$this->createSource('gallery_media', '42')], [$this->createNetwork('bluesky')], enabled: false, last: new \DateTimeImmutable());

        $this->assertCount(1, $publisher->prepareNext(true));
    }

    // What makes a dry run safe to try before any credential is plugged in: nothing written, nothing sent, the payload shown
    public function testADryRunShowsThePayloadAndWritesAndSendsNothing(): void
    {
        $publisher = $this->createPublisher([$this->createSource('gallery_media', '42')], [$this->createNetwork('bluesky')]);

        $report = $publisher->prepareNext(dryRun: true);

        $this->assertSame(SocialPublisher::DRY_RUN, $report['bluesky']['status']);
        $this->assertSame("Title 42\n\nhttps://example.org/42", $report['bluesky']['payload']['text']);
        $this->assertSame([], $this->persisted);
        $this->assertSame([], $this->published);
    }

    // The dry run is read before any credential is plugged in, so it shows the networks not configured yet too
    public function testADryRunShowsTheNetworksNotConfiguredYet(): void
    {
        $publisher = $this->createPublisher([$this->createSource('gallery_media', '42')], [$this->createNetwork('bluesky', automatic: false, configured: false)]);

        $report = $publisher->prepareNext(dryRun: true);

        $this->assertSame('review, not configured', $report['bluesky']['message']);
    }

    // Its drafts would wait on a screen the menu does not show while the publication is off
    public function testAPageIsNotPreparedWhileThePublicationIsOff(): void
    {
        $this->expectExceptionMessage('social-publish-enabled');

        $this->createPublisher([], [$this->createNetwork('bluesky')], enabled: false)->prepareUrl('https://example.org/page');
    }

    public function testARefusingNetworkLeavesAFailedTargetAndTheOthersPost(): void
    {
        $publisher = $this->createPublisher([$this->createSource('gallery_media', '42')], [$this->createNetwork('bluesky', failure: new \RuntimeException('Down')), $this->createNetwork('other')]);

        $report = $publisher->prepareNext();

        $this->assertSame(['status' => 'failed', 'message' => 'Down'], $report['bluesky']);
        $this->assertSame('published', $report['other']['status']);
    }

    // A photograph posted once is never offered again, a story may be after the days its source says
    public function testTheRepeatDelayOfTheSourceBoundsTheExcludedIds(): void
    {
        $this->createPublisher([$this->createSource('gallery_media', null), $this->createSource('story', null, repeatAfterDays: 30)], [$this->createNetwork('bluesky')])->prepareNext();

        $this->assertNull($this->asked['gallery_media']['since']);
        $this->assertEqualsWithDelta(new \DateTimeImmutable('-30 days')->getTimestamp(), $this->asked['story']['since']?->getTimestamp(), 5);
    }

    // The source that had a post least recently goes first, so photos and stories alternate rather than the first declared source always winning
    public function testTheSourceThatPostedLeastRecentlyGoesFirst(): void
    {
        $publisher = $this->createPublisher(
            [$this->createSource('gallery_media', '1'), $this->createSource('story', '2')],
            [$this->createNetwork('bluesky')],
            lastBySourceType: ['gallery_media' => '2026-09-22 10:00:00'],
        );

        $publisher->prepareNext();

        $this->assertSame('story', $this->preparedPost()->getSourceType());
    }

    public function testNothingLeftToPostAnywhereIsNoFailure(): void
    {
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createNetwork('bluesky')]);

        $this->assertSame([], $publisher->prepareNext());
    }

    public function testAPageIsPreparedFromItsOpenGraphTags(): void
    {
        $page = '<html><head><meta property="og:title" content="Le loup"><meta property="og:image" content="https://example.org/loup.png"></head></html>';
        $publisher = $this->createPublisher([], [$this->createNetwork('bluesky', automatic: false)], page: $page);

        $publisher->prepareUrl('https://example.org/histoires/loup');

        $post = $this->preparedPost();
        $this->assertSame(SocialPost::SOURCE_URL, $post->getSourceType());
        $this->assertSame(sha1('https://example.org/histoires/loup'), $post->getSourceId());
        $this->assertSame('https://example.org/loup.png', $post->getImageUrl());
    }

    // Reviewed a day later, a post goes out with the content as its source now has it - a photograph moved meanwhile took its file along
    public function testPublishReadsTheContentAgainAndSendsOnlyWhatIsNotOut(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null);
        new SocialPostTarget($post, 'bluesky', 'Text');
        new SocialPostTarget($post, 'other', 'Text')->markPublished('already');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createNetwork('bluesky'), $this->createNetwork('other')]);

        $report = $publisher->publish($post);

        $this->assertSame(['bluesky:/moved.webp'], $this->published);
        $this->assertSame('published', $report['bluesky']['status']);
        $this->assertSame('already', $report['other']['message']);
    }

    // A second click while the first "Publish" still waits on the networks sends nothing: the targets are still pending, and would go out twice
    public function testAPostBeingPublishedIsNotSentAgain(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null);
        new SocialPostTarget($post, 'bluesky', 'Text');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createNetwork('bluesky')]);
        // Held in a variable: a lock nothing refers to anymore releases itself
        $firstClick = $this->lockFactory->createLock('social_post_publish_' . $post->getId());
        $firstClick->acquire();

        $this->assertNull($publisher->publish($post));
        $this->assertSame([], $this->published);
        $this->assertSame(SocialPostStatus::Draft, $post->getTargets()->first()->getStatus());
    }

    public function testAContentGoneSinceItWasPreparedFailsItsTargets(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null);
        new SocialPostTarget($post, 'bluesky', 'Text');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null, gone: true)], [$this->createNetwork('bluesky')]);

        $publisher->publish($post);

        $this->assertSame(SocialPostStatus::Failed, $post->getTargets()->first()->getStatus());
        $this->assertSame([], $this->published);
    }

    // A post read from a page has no source to ask again: it goes out with the image url it was prepared with
    public function testAPostReadFromAPageGoesOutWithItsOwnImageUrl(): void
    {
        $post = new SocialPost(SocialPost::SOURCE_URL, sha1('x'), 'Title', 'https://example.org/x', 'https://example.org/x.png');
        new SocialPostTarget($post, 'bluesky', 'Text');
        $publisher = $this->createPublisher([], [$this->createNetwork('bluesky')]);

        $publisher->publish($post);

        $this->assertSame(['bluesky:https://example.org/x.png'], $this->published);
    }

    public function testMaxLengthIsAskedOfTheNetwork(): void
    {
        $publisher = $this->createPublisher([], [$this->createNetwork('bluesky')]);

        $this->assertSame(300, $publisher->getMaxLength('bluesky'));
        $this->assertNull($publisher->getMaxLength('unknown'));
    }

    // While a slot is on, the slots are the pace: the hourly run on the interval would post in between
    public function testTheIntervalRunStandsAsideWhileASlotIsEnabled(): void
    {
        $this->assertSame([], $this->createPublisher([$this->createSource('gallery_media', '42')], [$this->createNetwork('bluesky')], slotEnabled: true)->prepareNext());
        $this->assertNotSame([], $this->createPublisher([$this->createSource('gallery_media', '42')], [$this->createNetwork('bluesky')], slotEnabled: true)->prepareNext(true));
    }

    // Its own networks only, its own text where the common template says "{slot}", and what went out on those networks alone left out
    public function testASlotPostsOnItsNetworksWithItsText(): void
    {
        $publisher = $this->createPublisher([$this->createSource('gallery_media', '42')], [$this->createNetwork('bluesky'), $this->createNetwork('instagram')], template: "{title}\n\n{slot}");
        $slot = new SocialSchedule()->setNetworks(['instagram'])->setText('#photo');

        $report = $publisher->prepareSlot($slot);

        $this->assertSame(['instagram'], array_keys($report));
        $this->assertSame(['instagram'], $this->asked['gallery_media']['networks']);
        $target = $this->preparedPost()->getTargets()->first();
        $this->assertSame("Title 42\n\n#photo", $target ? $target->getText() : null);
    }

    public function testASlotWithoutNetworksPostsOnEveryConfiguredOne(): void
    {
        $publisher = $this->createPublisher([$this->createSource('gallery_media', '42')], [$this->createNetwork('bluesky'), $this->createNetwork('instagram', configured: false)]);

        $this->assertSame(['bluesky'], array_keys($publisher->prepareSlot(new SocialSchedule())));
    }

    // A slot naming a network this site has not configured has nowhere to post
    public function testASlotWithNoConfiguredNetworkPreparesNothing(): void
    {
        $publisher = $this->createPublisher([$this->createSource('gallery_media', '42')], [$this->createNetwork('bluesky'), $this->createNetwork('instagram', configured: false)]);

        $this->assertSame([], $publisher->prepareSlot(new SocialSchedule()->setNetworks(['instagram'])));
        $this->assertSame([], $this->persisted);
    }

    public function testASlotPreparesNothingWhileThePublicationIsOff(): void
    {
        $this->assertSame([], $this->createPublisher([$this->createSource('gallery_media', '42')], [$this->createNetwork('bluesky')], false)->prepareSlot(new SocialSchedule()));
    }

    // Its sources only, and within a source only the groups it picked
    public function testASlotDrawsFromItsSourcesAndTheirGroups(): void
    {
        $publisher = $this->createPublisher([$this->createSource('story', '1'), $this->createScopedSource('gallery_media', '42')], [$this->createNetwork('bluesky')], lastBySourceType: ['gallery_media' => '2026-01-01']);

        $publisher->prepareSlot(new SocialSchedule()->setSources(['gallery_media:3', 'gallery_media:5']));

        $this->assertSame('42', $this->preparedPost()->getSourceId());
        $this->assertArrayNotHasKey('story', $this->asked);
        $this->assertSame(['3', '5'], $this->asked['gallery_media']['scopes']);
    }

    // A whole source picked beside some of its groups takes it whole
    public function testASlotTakingAWholeSourceIgnoresItsGroups(): void
    {
        $publisher = $this->createPublisher([$this->createScopedSource('gallery_media', '42')], [$this->createNetwork('bluesky')]);

        $publisher->prepareSlot(new SocialSchedule()->setSources(['gallery_media', 'gallery_media:3']));

        $this->assertSame([], $this->asked['gallery_media']['scopes']);
    }

    public function testTheSourceChoicesListEachSourceThenItsGroups(): void
    {
        $publisher = $this->createPublisher([$this->createSource('story', '1'), $this->createScopedSource('gallery_media', '42')], []);

        $this->assertSame(['story' => 'Story', 'gallery_media' => 'Gallery media', 'gallery_media:3' => 'Gallery media - Mountain'], $publisher->getSourceChoices());
    }

    // Two groups of the same name stay two choices, the second told apart by its id
    public function testTwoGroupsOfTheSameNameKeepTwoLabels(): void
    {
        $publisher = $this->createPublisher([$this->createScopedSource('gallery_media', '42', ['3' => '2024', '7' => '2024'])], []);

        $this->assertSame(['gallery_media' => 'Gallery media', 'gallery_media:3' => 'Gallery media - 2024', 'gallery_media:7' => 'Gallery media - 2024 (#7)'], $publisher->getSourceChoices());
    }
}
