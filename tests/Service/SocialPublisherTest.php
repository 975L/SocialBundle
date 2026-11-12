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
use c975L\ConfigBundle\Service\SiteUrlResolver;
use c975L\SocialBundle\Contract\FirstCommentPublisherInterface;
use c975L\SocialBundle\Contract\NetworkPublisherInterface;
use c975L\SocialBundle\Contract\PostOptionsPublisherInterface;
use c975L\SocialBundle\Contract\ProfileResharePublisherInterface;
use c975L\SocialBundle\Contract\RepostPublisherInterface;
use c975L\SocialBundle\Contract\StoryPublisherInterface;
use c975L\SocialBundle\Entity\SocialMedia;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Enum\SocialPostStatus;
use c975L\SocialBundle\Model\MediaRules;
use c975L\SocialBundle\Model\PostMedia;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Service\SocialPageReader;
use c975L\SocialBundle\Service\SocialPostTextBuilder;
use c975L\SocialBundle\Service\SocialPostWriter;
use c975L\SocialBundle\Service\SocialPublisher;
use c975L\UiBundle\Contract\BrowsableSocialContentSourceInterface;
use c975L\UiBundle\Contract\DatedSocialContentSourceInterface;
use c975L\UiBundle\Contract\ScopedSocialContentSourceInterface;
use c975L\UiBundle\Contract\SocialContentSourceInterface;
use c975L\UiBundle\Model\SocialContent;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Translation\TranslatableMessage;

class SocialPublisherTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    /** @var list<object> */
    private array $removed = [];

    /** @var array<string, array{excluded?: list<string>, since?: ?\DateTimeImmutable, scopes?: list<string>, networks?: list<string>}> */
    private array $asked = [];

    /** @var list<string> */
    // The flushes the entity manager was asked
    private int $flushes = 0;

    private array $published = [];

    private ?LockFactory $lockFactory = null;

    private function createSource(string $type, ?string $nextId, ?int $repeatAfterDays = null, bool $gone = false, bool $pictureless = false): SocialContentSourceInterface
    {
        $source = $this->createStub(SocialContentSourceInterface::class);
        $source->method('getSourceType')->willReturn($type);
        $source->method('getRepeatAfterDays')->willReturn($repeatAfterDays);
        $source->method('getNextContent')->willReturnCallback(function (array $excludedIds) use ($type, $nextId): ?SocialContent {
            $this->asked[$type]['excluded'] = $excludedIds;

            return null === $nextId ? null : new SocialContent($nextId, 'Title ' . $nextId, 'https://example.org/' . $nextId);
        });
        $source->method('getContent')->willReturnCallback(static fn (string $id): ?SocialContent => $gone ? null : new SocialContent($id, 'Fresh', 'https://example.org/' . $id, imagePath: $pictureless ? null : '/moved.webp'));

        return $source;
    }

    // A source choosing by date, its contents keyed by day and the days it was asked for being recorded
    /** @param array<string, string> $contents */
    private function createDatedSource(string $type, array $contents): DatedSocialContentSourceInterface
    {
        $source = $this->createStub(DatedSocialContentSourceInterface::class);
        $source->method('getSourceType')->willReturn($type);
        $source->method('getContentForDate')->willReturnCallback(function (\DateTimeImmutable $date, array $excludedIds) use ($type, $contents): ?SocialContent {
            $day = $date->format('Y-m-d');
            $this->asked[$type]['dates'][] = $day;
            $this->asked[$type]['excluded'] = $excludedIds;

            return isset($contents[$day]) ? new SocialContent($contents[$day], 'Title', 'https://example.org/' . $contents[$day]) : null;
        });

        return $source;
    }

    // A source answering $left times, then running out, the times it was asked being recorded
    private function createCountedSource(string $type, int $left): SocialContentSourceInterface
    {
        $source = $this->createStub(SocialContentSourceInterface::class);
        $source->method('getSourceType')->willReturn($type);
        $source->method('getNextContent')->willReturnCallback(function () use ($type, &$left): ?SocialContent {
            $this->asked[$type]['times'] = ($this->asked[$type]['times'] ?? 0) + 1;

            return $left-- > 0 ? new SocialContent('item-' . $left, 'Title', 'https://example.org/item') : null;
        });

        return $source;
    }

    // A source whose contents fall into groups, the groups it was asked for being recorded
    /** @param array<int|string, string> $scopes */
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

    // A gallery whose contents may be changed on a post's screen: '42' in the group '3', the next one drawn there '43'
    private function createBrowsableSource(): BrowsableSocialContentSourceInterface
    {
        $source = $this->createStub(BrowsableSocialContentSourceInterface::class);
        $source->method('getSourceType')->willReturn('gallery_media');
        $source->method('getContentScope')->willReturn('3');
        $source->method('getContent')->willReturnCallback(static fn (string $id): SocialContent => new SocialContent($id, 'Photo ' . $id, 'https://example.org/' . $id, imageUrl: 'https://example.org/' . $id . '.jpg'));
        $source->method('getNextContent')->willReturn(new SocialContent('43', 'Photo 43', 'https://example.org/43'));
        $source->method('findContents')->willReturnCallback(function (array $excludedIds, array $scopeIds, int $limit): array {
            $this->asked['gallery_media'] = ['excluded' => $excludedIds, 'scopes' => $scopeIds];

            return [new SocialContent('44', 'Photo 44', 'https://example.org/44')];
        });

        return $source;
    }

    private function createNetwork(string $name, bool $configured = true, ?\Throwable $failure = null, bool $videoOnly = false): NetworkPublisherInterface
    {
        $network = $this->createStub(NetworkPublisherInterface::class);
        $network->method('getName')->willReturn($name);
        $network->method('isConfigured')->willReturn($configured);
        $network->method('getMaxLength')->willReturn(300);
        $network->method('getMediaRules')->willReturn(new MediaRules(maxImages: $videoOnly ? 0 : 4, video: true, videoRequired: $videoOnly));
        $network->method('preview')->willReturnCallback(static fn (string $text): array => ['text' => $text]);
        $network->method('publish')->willReturnCallback(function (string $text, SocialContent $content, array $medias = []) use ($name, $failure): string {
            if (null !== $failure) {
                throw $failure;
            }
            $this->published[] = $name . ':' . ($content->imagePath ?? $content->imageUrl ?? '') . implode(',', array_map(static fn (PostMedia $media): string => $media->url, $medias));

            return $name . '-post-id';
        });

        return $network;
    }

    /**
     * @param list<SocialContentSourceInterface> $sources
     * @param list<NetworkPublisherInterface>    $networks
     * @param array<string, string>              $lastBySourceType
     * @param list<SocialPost>                   $approved
     * @param array<string, string>              $written
     * @param list<SocialPost>                   $holders
     */
    private function createPublisher(array $sources, array $networks, array $lastBySourceType = [], string $page = '', array $approved = [], array $written = [], array $holders = []): SocialPublisher
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap([
            ['social-publish-template', null],
        ]);

        $repository = $this->createStub(SocialPostRepository::class);
        $repository->method('findLastCreatedAtBySourceType')->willReturn($lastBySourceType);
        $repository->method('findApproved')->willReturn($approved);
        $repository->method('findBySourceIds')->willReturn($holders);
        $repository->method('findSourceIds')->willReturnCallback(function (string $type, ?\DateTimeImmutable $since): array {
            $this->asked[$type]['since'] = $since;

            return ['7'];
        });

        // What the site's AI wrote, by network - none by default, the template then writing every text
        $writer = $this->createStub(SocialPostWriter::class);
        $writer->method('write')->willReturnCallback(function (SocialContent $content, array $maxLengths) use ($written): array {
            $this->asked['writer']['networks'] = array_keys($maxLengths);

            return $written;
        });

        $siteUrlResolver = $this->createStub(SiteUrlResolver::class);
        $siteUrlResolver->method('siteUrl')->willReturn('https://example.org');

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });
        $entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            $this->removed[] = $entity;
        });
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        return new SocialPublisher(
            $sources,
            $networks,
            new SocialPostTextBuilder($configService),
            new SocialPageReader(new MockHttpClient(new MockResponse($page))),
            $repository,
            $entityManager,
            new NullLogger(),
            $this->lockFactory ??= new LockFactory(new InMemoryStore()),
            $writer,
            $siteUrlResolver,
            '/srv/site',
        );
    }

    private function preparedPost(): SocialPost
    {
        $this->assertCount(1, $this->persisted);
        $this->assertInstanceOf(SocialPost::class, $this->persisted[0]);

        return $this->persisted[0];
    }

    // An approved post planned at $plannedAt, one approved target per network
    private function approvedPost(\DateTimeImmutable $plannedAt, string ...$networks): SocialPost
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, $plannedAt);
        foreach ($networks as $network) {
            new SocialPostTarget($post, $network, 'Approved text');
        }
        $post->approve();

        return $post;
    }

    // One draft per moment, each planned at its own, sent nowhere
    public function testADraftIsPreparedForEachMomentAndSentNowhere(): void
    {
        $publisher = $this->createPublisher([$this->createSource('gallery_media', '42')], [$this->createNetwork('bluesky'), $this->createNetwork('facebook')]);
        $moments = [new \DateTimeImmutable('2026-10-06 09:00'), new \DateTimeImmutable('2026-10-07 18:30')];

        $posts = $publisher->prepareDrafts($moments);

        $this->assertCount(2, $posts);
        $this->assertSame($this->persisted, $posts);
        $this->assertEquals($moments[0], $posts[0]->getPlannedAt());
        $this->assertEquals($moments[1], $posts[1]->getPlannedAt());
        $this->assertSame(['bluesky', 'facebook'], $posts[0]->getNetworks());
        $this->assertSame([SocialPostStatus::Draft, SocialPostStatus::Draft], $posts[0]->getTargets()->map(static fn (SocialPostTarget $target): SocialPostStatus => $target->getStatus())->getValues());
        $this->assertSame([], $this->published);
        $this->assertSame(['7'], $this->asked['gallery_media']['excluded']);
    }

    // A content brings no video: a network of videos alone gets no target, nor any text written for it
    public function testADraftLeavesOutANetworkOfVideosAlone(): void
    {
        $publisher = $this->createPublisher([$this->createSource('gallery_media', '42')], [$this->createNetwork('bluesky'), $this->createNetwork('youtube', videoOnly: true)]);

        $posts = $publisher->prepareDrafts([new \DateTimeImmutable('2026-10-06 09:00')]);

        $this->assertSame(['bluesky'], $posts[0]->getNetworks());
        $this->assertSame(['bluesky'], $this->asked['writer']['networks']);
    }

    // No moment asked, no draft
    public function testNoMomentPreparesNothing(): void
    {
        $this->assertSame([], $this->createPublisher([$this->createSource('gallery_media', '42')], [$this->createNetwork('bluesky')])->prepareDrafts([]));
        $this->assertSame([], $this->persisted);
    }

    // A network not configured gets no target
    public function testAnUnconfiguredNetworkGetsNoTarget(): void
    {
        $publisher = $this->createPublisher([$this->createSource('gallery_media', '42')], [$this->createNetwork('bluesky'), $this->createNetwork('other', configured: false)]);

        $publisher->prepareDrafts([new \DateTimeImmutable('+1 day')]);

        $this->assertSame(['bluesky'], $this->preparedPost()->getNetworks());
    }

    // A post with no target would still take its content, which would never be offered again
    public function testNothingIsPreparedWhileNoNetworkIsConnected(): void
    {
        $publisher = $this->createPublisher([$this->createSource('gallery_media', '42')], [$this->createNetwork('bluesky', configured: false)]);

        $this->assertSame([], $publisher->prepareDrafts([new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+2 days')]));
        $this->assertSame([], $this->persisted);
        $this->assertArrayNotHasKey('gallery_media', $this->asked);
    }

    // The contents running out end the batch early, no failure
    public function testABatchStopsWhenTheContentsRunOut(): void
    {
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createNetwork('bluesky')]);

        $this->assertSame([], $publisher->prepareDrafts([new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+2 days')]));
        $this->assertSame([], $this->persisted);
    }

    // A photograph posted once is never offered again, a story may be after the days its source says
    public function testTheRepeatDelayOfTheSourceBoundsTheExcludedIds(): void
    {
        $this->createPublisher([$this->createSource('gallery_media', null), $this->createSource('story', null, repeatAfterDays: 30)], [$this->createNetwork('bluesky')])->prepareDrafts([new \DateTimeImmutable('+1 day')]);

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

        $publisher->prepareDrafts([new \DateTimeImmutable('+1 day')]);

        $this->assertSame('story', $this->preparedPost()->getSourceType());
    }

    // The sources picked only, and within a source only the groups picked
    public function testTheDraftsDrawFromThePickedSourcesAndTheirGroups(): void
    {
        $publisher = $this->createPublisher([$this->createSource('story', '1'), $this->createScopedSource('gallery_media', '42')], [$this->createNetwork('bluesky')], lastBySourceType: ['gallery_media' => '2026-01-01']);

        $publisher->prepareDrafts([new \DateTimeImmutable('+1 day')], ['gallery_media:3', 'gallery_media:5']);

        $this->assertSame('42', $this->preparedPost()->getSourceId());
        $this->assertArrayNotHasKey('story', $this->asked);
        $this->assertSame(['3', '5'], $this->asked['gallery_media']['scopes']);
    }

    // A whole source picked beside some of its groups takes it whole
    public function testAWholeSourcePickedIgnoresItsGroups(): void
    {
        $publisher = $this->createPublisher([$this->createScopedSource('gallery_media', '42')], [$this->createNetwork('bluesky')]);

        $publisher->prepareDrafts([new \DateTimeImmutable('+1 day')], ['gallery_media', 'gallery_media:3']);

        $this->assertSame([], $this->asked['gallery_media']['scopes']);
    }

    // No source picked draws from every source, a scoped one being asked for its content whole
    public function testNoSourcePickedDrawsFromEverySource(): void
    {
        $publisher = $this->createPublisher([$this->createSource('story', null), $this->createScopedSource('gallery_media', '42')], [$this->createNetwork('bluesky')]);

        $publisher->prepareDrafts([new \DateTimeImmutable('+1 day')]);

        $this->assertSame('gallery_media', $this->preparedPost()->getSourceType());
        $this->assertSame([], $this->asked['gallery_media']['scopes']);
        $this->assertArrayHasKey('story', $this->asked);
    }

    // A dated source is asked for each moment's date with the ids it may not give, and a day without content passes to the next instead of ending the series
    public function testADatedSourcePassesOverTheMomentsWithoutContent(): void
    {
        $publisher = $this->createPublisher([$this->createDatedSource('dated', ['2026-11-04' => 'item-1', '2026-11-06' => 'item-2'])], [$this->createNetwork('bluesky')]);
        $moments = array_map(static fn (string $day): \DateTimeImmutable => new \DateTimeImmutable($day . ' 09:00'), ['2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06']);

        $posts = $publisher->prepareDrafts($moments);

        $this->assertSame(['2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'], $this->asked['dated']['dates']);
        $this->assertSame(['7'], $this->asked['dated']['excluded']);
        $this->assertSame(['item-1', 'item-2'], array_map(static fn (SocialPost $post): string => $post->getSourceId(), $posts));
        $this->assertEquals([$moments[1], $moments[3]], array_map(static fn (SocialPost $post): \DateTimeImmutable => $post->getPlannedAt(), $posts));
    }

    // Without a dated source the first moment without content still ends the batch: the next moments ask nothing
    public function testSourcesWithoutDatesStillStopAtTheFirstMomentWithoutContent(): void
    {
        $publisher = $this->createPublisher([$this->createCountedSource('story', 1)], [$this->createNetwork('bluesky')]);

        $posts = $publisher->prepareDrafts([new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+2 days'), new \DateTimeImmutable('+3 days')]);

        $this->assertCount(1, $posts);
        $this->assertSame(2, $this->asked['story']['times']);
    }

    // A dated source installed but not picked changes nothing: the sources picked ran out for good
    public function testADatedSourceNotPickedLeavesTheStopAsItWas(): void
    {
        $publisher = $this->createPublisher([$this->createCountedSource('story', 0), $this->createDatedSource('dated', ['2026-11-04' => 'item-1'])], [$this->createNetwork('bluesky')]);

        $this->assertSame([], $publisher->prepareDrafts([new \DateTimeImmutable('2026-11-03 09:00'), new \DateTimeImmutable('2026-11-04 09:00')], ['story']));
        $this->assertSame(1, $this->asked['story']['times']);
        $this->assertArrayNotHasKey('dated', $this->asked);
    }

    // Which sources ask a date: a dated one picked, by its whole or a group, or none picked at all
    public function testHasDatedSourceFollowsTheSourcesPicked(): void
    {
        $publisher = $this->createPublisher([$this->createSource('story', '1'), $this->createDatedSource('dated', [])], []);

        $this->assertTrue($publisher->hasDatedSource([]));
        $this->assertTrue($publisher->hasDatedSource(['dated']));
        $this->assertTrue($publisher->hasDatedSource(['story', 'dated:3']));
        $this->assertFalse($publisher->hasDatedSource(['story']));
        $this->assertFalse($this->createPublisher([$this->createSource('story', '1')], [])->hasDatedSource([]));
    }

    // No network connected asks no source, a dated one included
    public function testADatedSourceIsNotAskedWithoutANetwork(): void
    {
        $publisher = $this->createPublisher([$this->createDatedSource('dated', ['2026-11-03' => 'item-1'])], []);

        $this->assertSame([], $publisher->prepareDrafts([new \DateTimeImmutable('2026-11-03 09:00')]));
        $this->assertArrayNotHasKey('dated', $this->asked);
    }

    // A dated source's turn with nothing at this date goes to the next source in that same moment, and it is asked first again at the next one, having had no post
    public function testADatedSourceWithoutContentLeavesTheMomentToTheNextSource(): void
    {
        $publisher = $this->createPublisher(
            [$this->createSource('story', '2'), $this->createDatedSource('dated', ['2026-11-04' => 'item-1'])],
            [$this->createNetwork('bluesky')],
            lastBySourceType: ['dated' => '2026-09-01 10:00:00', 'story' => '2026-09-02 10:00:00'],
        );

        $posts = $publisher->prepareDrafts([new \DateTimeImmutable('2026-11-03 09:00'), new \DateTimeImmutable('2026-11-04 09:00')]);

        $this->assertSame(['story', 'dated'], array_map(static fn (SocialPost $post): string => $post->getSourceType(), $posts));
    }

    // The source that posted least recently keeps its turn over a dated one, which is asked once it has nothing
    public function testADatedSourceTakesItsTurnAmongTheOthers(): void
    {
        $publisher = $this->createPublisher(
            [$this->createDatedSource('dated', ['2026-11-03' => 'item-1']), $this->createSource('story', '2')],
            [$this->createNetwork('bluesky')],
            lastBySourceType: ['dated' => '2026-09-02 10:00:00', 'story' => '2026-09-01 10:00:00'],
        );

        $publisher->prepareDrafts([new \DateTimeImmutable('2026-11-03 09:00')]);

        $this->assertSame('story', $this->preparedPost()->getSourceType());
        $this->assertArrayNotHasKey('dated', $this->asked);
    }

    // Two sources prepared within one second stay tied: the dated one goes first whatever the order they are declared in, its content being good for this date alone
    public function testAtEqualRecencyADatedSourceGoesFirst(): void
    {
        $publisher = $this->createPublisher(
            [$this->createSource('story', '2'), $this->createDatedSource('dated', ['2026-11-03' => 'item-1'])],
            [$this->createNetwork('bluesky')],
            lastBySourceType: ['dated' => '2026-09-01 10:00:00', 'story' => '2026-09-01 10:00:00'],
        );

        $publisher->prepareDrafts([new \DateTimeImmutable('2026-11-03 09:00')]);

        $this->assertSame('dated', $this->preparedPost()->getSourceType());
    }

    // A source both dated and grouped answers by date, its contract taking no group
    public function testADatedSourceWithGroupsAnswersByDate(): void
    {
        $source = new class implements DatedSocialContentSourceInterface, ScopedSocialContentSourceInterface {
            public function getSourceType(): string
            {
                return 'dated';
            }

            public function getRepeatAfterDays(): ?int
            {
                return null;
            }

            public function getNextContent(array $excludedIds): ?SocialContent
            {
                return null;
            }

            public function getContent(string $sourceId): ?SocialContent
            {
                return null;
            }

            public function getScopes(): array
            {
                return ['3' => 'Group'];
            }

            public function getNextScopedContent(array $excludedIds, array $scopeIds): ?SocialContent
            {
                return new SocialContent('item-2', 'Title', 'https://example.org/item-2');
            }

            public function getContentForDate(\DateTimeImmutable $date, array $excludedIds): ?SocialContent
            {
                return new SocialContent('item-1', 'Title', 'https://example.org/item-1');
            }
        };

        $this->createPublisher([$source], [$this->createNetwork('bluesky')])->prepareDrafts([new \DateTimeImmutable('2026-11-03 09:00')], ['dated:3']);

        $this->assertSame('item-1', $this->preparedPost()->getSourceId());
    }

    // A post built from the sources takes the content of its own date, and null for a date without one
    public function testAPostFromTheSourcesTakesTheContentOfItsDate(): void
    {
        $publisher = $this->createPublisher([$this->createDatedSource('dated', ['2026-11-03' => 'item-1'])], [$this->createNetwork('bluesky')]);

        $post = $publisher->createFromSources(new \DateTimeImmutable('2026-11-03 09:00'), ['dated']);

        $this->assertSame('item-1', $post?->getSourceId());
        $this->assertNull($publisher->createFromSources(new \DateTimeImmutable('2026-11-04 09:00'), ['dated']));
        $this->assertSame(['2026-11-03', '2026-11-04'], $this->asked['dated']['dates']);
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

    // A page is read from its Open Graph tags, planned at the moment given and left a draft
    public function testAPageIsPreparedAsADraftPlannedAtTheGivenMoment(): void
    {
        $page = '<html><head><meta property="og:title" content="Le loup"><meta property="og:image" content="https://example.org/loup.png"></head></html>';
        $publisher = $this->createPublisher([], [$this->createNetwork('bluesky')], page: $page);
        $at = new \DateTimeImmutable('2026-10-06 19:00');

        $report = $publisher->prepareUrl('https://93.184.216.34/histoires/loup', $at);

        $this->assertSame(['bluesky' => ['status' => 'draft', 'message' => '']], $report);
        $post = $this->preparedPost();
        $this->assertSame(SocialPost::SOURCE_URL, $post->getSourceType());
        $this->assertSame(sha1('https://93.184.216.34/histoires/loup'), $post->getSourceId());
        $this->assertSame('https://example.org/loup.png', $post->getImageUrl());
        $this->assertEquals($at, $post->getPlannedAt());
        $this->assertSame(SocialPostStatus::Draft, $post->getTargets()->first()->getStatus());
        $this->assertSame([], $this->published);
    }

    // Its drafts would have no network to go out on
    public function testAPageIsNotPreparedWhileNoNetworkIsConnected(): void
    {
        $this->expectExceptionMessageIsOrContains('No network is connected yet');

        $this->createPublisher([], [$this->createNetwork('bluesky', configured: false)])->prepareUrl('https://93.184.216.34/page', new \DateTimeImmutable('+1 day'));
    }

    // What makes a dry run safe to try before any credential is plugged in: nothing written, nothing sent, the AI not asked, the template's payload shown
    public function testADryRunShowsThePayloadAndWritesAndSendsNothing(): void
    {
        $page = '<html><head><meta property="og:title" content="Le loup"></head></html>';
        $publisher = $this->createPublisher([], [$this->createNetwork('bluesky')], page: $page, written: ['bluesky' => 'Written by the AI']);

        $report = $publisher->prepareUrl('https://93.184.216.34/loup', new \DateTimeImmutable('+1 day'), true);

        $this->assertSame(SocialPublisher::DRY_RUN, $report['bluesky']['status']);
        $this->assertSame("Le loup\n\nhttps://93.184.216.34/loup", $report['bluesky']['payload']['text']);
        $this->assertSame([], $this->persisted);
        $this->assertSame([], $this->published);
        $this->assertArrayNotHasKey('writer', $this->asked);
    }

    // The dry run is read before any credential is plugged in, so it shows the networks not configured yet too
    public function testADryRunShowsTheNetworksNotConfiguredYet(): void
    {
        $publisher = $this->createPublisher([], [$this->createNetwork('bluesky', configured: false)], page: '<html><head><meta property="og:title" content="Page"></head></html>');

        $report = $publisher->prepareUrl('https://93.184.216.34/page', new \DateTimeImmutable('+1 day'), true);

        $this->assertSame('not configured', $report['bluesky']['message']);
    }

    // The planned run sends an approved post whose moment has come, on its approved networks only
    public function testThePlannedRunSendsAnApprovedPostWhoseMomentHasCome(): void
    {
        $post = $this->approvedPost(new \DateTimeImmutable('-5 minutes'), 'bluesky');
        new SocialPostTarget($post, 'instagram', 'Draft text');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', '43')], [$this->createNetwork('bluesky'), $this->createNetwork('instagram')], approved: [$post]);

        $report = $publisher->publishPlanned();

        $this->assertSame(['# bluesky' => ['status' => 'published', 'message' => 'bluesky-post-id', 'post' => null, 'network' => 'bluesky']], $report);
        $this->assertSame(['bluesky:/moved.webp'], $this->published);
        $this->assertSame(SocialPostStatus::Draft, $post->getTargets()->last()->getStatus());
    }

    // An approved post planned later waits for its moment
    public function testThePlannedRunSkipsAPostPlannedLater(): void
    {
        $post = $this->approvedPost(new \DateTimeImmutable('+1 hour'), 'bluesky');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', '43')], [$this->createNetwork('bluesky')], approved: [$post]);

        $this->assertSame([], $publisher->publishPlanned());
        $this->assertSame([], $this->published);
        $this->assertSame(SocialPostStatus::Approved, $post->getTargets()->first()->getStatus());
    }

    // The dry run of the planned run shows the approved texts it would send, and sends none of them
    public function testADryRunOfThePlannedRunShowsTheApprovedPost(): void
    {
        $post = $this->approvedPost(new \DateTimeImmutable('-5 minutes'), 'bluesky');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', '43')], [$this->createNetwork('bluesky')], approved: [$post]);

        $report = $publisher->publishPlanned(true);

        $this->assertSame(['text' => 'Approved text'], $report['# bluesky']['payload']);
        $this->assertSame([], $this->published);
        $this->assertSame(SocialPostStatus::Approved, $post->getTargets()->first()->getStatus());
    }

    // Reviewed a day later, a post goes out with the content as its source now has it - a photograph moved meanwhile took its file along
    public function testPublishReadsTheContentAgainAndSendsOnlyWhatIsNotOut(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'bluesky', 'Text');
        new SocialPostTarget($post, 'other', 'Text')->markPublished('already');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createNetwork('bluesky'), $this->createNetwork('other')]);

        $report = $publisher->publish($post);

        $this->assertSame(['bluesky:/moved.webp'], $this->published);
        $this->assertSame('published', $report['bluesky']['status']);
        $this->assertSame('already', $report['other']['message']);
    }

    // Each network saved as soon as it was sent: a process killed on the next one never sends the first again
    public function testEachNetworkIsSavedOnceSent(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'bluesky', 'Text');
        new SocialPostTarget($post, 'other', 'Text');
        $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createNetwork('bluesky'), $this->createNetwork('other')])->publish($post);

        $this->assertSame(3, $this->flushes);
    }

    // One network refusing leaves a failed target, the others still posting
    public function testARefusingNetworkLeavesAFailedTargetAndTheOthersPost(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'bluesky', 'Text');
        new SocialPostTarget($post, 'other', 'Text');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createNetwork('bluesky', failure: new \RuntimeException('Down')), $this->createNetwork('other')]);

        $report = $publisher->publish($post);

        $this->assertSame(['status' => 'failed', 'message' => 'Down'], $report['bluesky']);
        $this->assertSame('published', $report['other']['status']);
    }

    // Sent again from the refusal email, only the refused targets go out, a draft never reviewed staying a draft
    public function testRetryingSendsOnlyTheFailedTargets(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'bluesky', 'Text')->markFailed('Down');
        new SocialPostTarget($post, 'other', 'Text');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createNetwork('bluesky'), $this->createNetwork('other')]);

        $report = $publisher->retryFailed($post);

        $this->assertSame(['bluesky'], array_keys((array) $report));
        $this->assertSame(['bluesky:/moved.webp'], $this->published);
        $this->assertSame(SocialPostStatus::Draft, $post->getTargets()->last()->getStatus());
    }

    // Sent again from the calendar panel for one network, the other refused ones wait their turn
    public function testRetryingOneNetworkLeavesTheOtherRefusedOnes(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'bluesky', 'Text')->markFailed('Down');
        new SocialPostTarget($post, 'other', 'Text')->markFailed('Down');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createNetwork('bluesky'), $this->createNetwork('other')]);

        $report = $publisher->retryFailed($post, 'bluesky');

        $this->assertSame(['bluesky'], array_keys((array) $report));
        $this->assertSame(SocialPostStatus::Failed, $post->getTargets()->last()->getStatus());
    }

    // A second click while the first "Publish" still waits on the networks sends nothing: the targets are still pending, and would go out twice
    public function testAPostBeingPublishedIsNotSentAgain(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'bluesky', 'Text');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createNetwork('bluesky')]);
        // Held in a variable: a lock nothing refers to anymore releases itself
        $firstClick = $this->lockFactory->createLock('social_post_publish_' . $post->getId());
        $firstClick->acquire();

        $this->assertNull($publisher->publish($post));
        $this->assertSame([], $this->published);
        $this->assertSame(SocialPostStatus::Draft, $post->getTargets()->first()->getStatus());
    }

    // A post is deleted once no "Publish" holds it, what goes out meanwhile would leave no record
    public function testAPostBeingPublishedIsNotDeleted(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'bluesky', 'Text');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createNetwork('bluesky')]);
        // Held in a variable: a lock nothing refers to anymore releases itself
        $firstClick = $this->lockFactory->createLock('social_post_publish_' . $post->getId());
        $firstClick->acquire();

        $this->assertFalse($publisher->delete($post));
        $this->assertSame([], $this->removed);

        $firstClick->release();
        $this->assertTrue($publisher->delete($post));
        $this->assertSame([$post], $this->removed);
    }

    // A post gone out stays when asked to, the list's deletion alone removing it
    public function testAPostGoneOutIsKeptWhenAsked(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'bluesky', 'Text')->markPublished('ext-1');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createNetwork('bluesky')]);

        $this->assertFalse($publisher->delete($post, true));
        $this->assertSame([], $this->removed);

        $this->assertTrue($publisher->delete($post));
        $this->assertSame([$post], $this->removed);
    }

    // A content gone since it was prepared fails its targets rather than posting
    public function testAContentGoneSinceItWasPreparedFailsItsTargets(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'bluesky', 'Text');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null, gone: true)], [$this->createNetwork('bluesky')]);

        $publisher->publish($post);

        $this->assertSame(SocialPostStatus::Failed, $post->getTargets()->first()->getStatus());
        $this->assertSame([], $this->published);
    }

    // A post read from a page has no source to ask again: it goes out with the image url it was prepared with
    public function testAPostReadFromAPageGoesOutWithItsOwnImageUrl(): void
    {
        $post = new SocialPost(SocialPost::SOURCE_URL, sha1('x'), 'Title', 'https://example.org/x', 'https://example.org/x.png', new \DateTimeImmutable());
        new SocialPostTarget($post, 'bluesky', 'Text');
        $publisher = $this->createPublisher([], [$this->createNetwork('bluesky')]);

        $publisher->publish($post);

        $this->assertSame(['bluesky:https://example.org/x.png'], $this->published);
    }

    // The longest text is asked of the network, none for an unknown one
    public function testMaxLengthIsAskedOfTheNetwork(): void
    {
        $publisher = $this->createPublisher([], [$this->createNetwork('bluesky')]);

        $this->assertSame(300, $publisher->getMaxLength('bluesky'));
        $this->assertNull($publisher->getMaxLength('unknown'));
    }

    // The AI writes the networks it can, in one call for all of them, the template the ones it left out
    public function testTheAiWritesWhatItCanAndTheTemplateTheRest(): void
    {
        $publisher = $this->createPublisher([$this->createSource('gallery_media', '42')], [$this->createNetwork('bluesky'), $this->createNetwork('facebook')], written: ['facebook' => 'Written by the AI']);

        $publisher->prepareDrafts([new \DateTimeImmutable('+1 day')]);

        $texts = $this->preparedPost()->getTargets()->map(static fn (SocialPostTarget $target): string => $target->getText())->getValues();
        $this->assertSame(["Title 42\n\nhttps://example.org/42", 'Written by the AI'], $texts);
        $this->assertSame(['bluesky', 'facebook'], $this->asked['writer']['networks']);
    }

    // A network ticked on the post's screen gets its text, approved with the rest of the post; one not connected gets none
    public function testANetworkTickedGetsItsTextApprovedWithThePost(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'bluesky', 'Text');
        $post->approve();
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createNetwork('bluesky'), $this->createNetwork('facebook'), $this->createNetwork('linkedin', configured: false)], written: ['facebook' => 'Written']);

        $publisher->addTargets($post, ['facebook', 'linkedin']);

        $this->assertSame(['bluesky', 'facebook'], $post->getNetworks());
        $this->assertSame(['bluesky', 'facebook'], $post->getApprovedNetworks());
        $this->assertSame('Written', $post->getTargets()->last()->getText());
    }

    // Unticked then ticked again on another screen's save, a repost reposts still: the text written for the calendar never goes out
    public function testARepostTickedAgainStaysARepost(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'bluesky', 'Repost: new story')->setRepost('other.bsky.social');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createNetwork('bluesky')]);

        $post->setNetworks([]);
        $post->setNetworks(['bluesky']);
        $publisher->addTargets($post, $post->takeAddedNetworks());

        $this->assertSame('other.bsky.social', $post->getTargets()->first()->getRepost());
    }

    // A post written on its screen is planned at the moment given and ticked on every network connected but those of videos alone, its texts written from its own once saved
    public function testAWrittenPostIsTickedOnEveryNetworkConnected(): void
    {
        $at = new \DateTimeImmutable('2026-10-06 09:15');
        $publisher = $this->createPublisher([], [$this->createNetwork('bluesky'), $this->createNetwork('linkedin', configured: false), $this->createNetwork('youtube', videoOnly: true)]);

        $post = $publisher->createManual($at);
        $this->assertTrue($post->isManual());
        $this->assertSame($at, $post->getPlannedAt());
        $this->assertSame(['bluesky'], $post->getNetworks());

        $post->setText('Hello');
        $publisher->rewriteTargets($post);
        $this->assertSame('Hello', $post->getTargets()->first()->getText());
    }

    // A post written on its screen gives each network its own text cut to the network's length, the AI never asked
    public function testAWrittenPostGivesEachNetworkItsTextCut(): void
    {
        $publisher = $this->createPublisher([], [$this->createNetwork('bluesky')], written: ['bluesky' => 'Written']);
        $post = new SocialPost(SocialPost::SOURCE_MANUAL, 'abc', '', '', null, new \DateTimeImmutable('+1 day'));
        $post->setText(str_repeat('a', 400));

        $publisher->addTargets($post, ['bluesky']);

        $this->assertSame(str_repeat('a', 299) . '…', $post->getTargets()->first()->getText());
        $this->assertArrayNotHasKey('writer', $this->asked);
    }

    // Drawn again, the post takes another content of its source, the one it held freed with it
    public function testARedrawTiesThePostToAnotherContent(): void
    {
        $publisher = $this->createPublisher([$this->createBrowsableSource()], [$this->createNetwork('bluesky')]);
        $post = new SocialPost('gallery_media', '42', 'Photo 42', 'https://example.org/42', null, new \DateTimeImmutable('+1 day'));

        $this->assertTrue($publisher->redraw($post));
        $this->assertSame(['43', 'Photo 43'], [$post->getSourceId(), $post->getTitle()]);
    }

    // Drawn again from a dated source, the post takes another content of its own planned date
    public function testARedrawFromADatedSourceKeepsThePlannedDate(): void
    {
        $source = new class implements DatedSocialContentSourceInterface, BrowsableSocialContentSourceInterface {
            public function getSourceType(): string
            {
                return 'dated';
            }

            public function getRepeatAfterDays(): ?int
            {
                return null;
            }

            public function getNextContent(array $excludedIds): ?SocialContent
            {
                return new SocialContent('item-undated', 'Title', 'https://example.org/item');
            }

            public function getContent(string $sourceId): ?SocialContent
            {
                return null;
            }

            public function findContents(array $excludedIds, array $scopeIds, int $limit): array
            {
                return [];
            }

            public function getContentScope(string $sourceId): ?string
            {
                return null;
            }

            public function getContentForDate(\DateTimeImmutable $date, array $excludedIds): ?SocialContent
            {
                return new SocialContent('item-' . $date->format('Ymd'), 'Title', 'https://example.org/item');
            }
        };
        $publisher = $this->createPublisher([$source], [$this->createNetwork('bluesky')]);
        $post = new SocialPost('dated', 'item-1', 'Title', 'https://example.org/item-1', null, new \DateTimeImmutable('2026-11-03 09:00'));

        $this->assertTrue($publisher->redraw($post));
        $this->assertSame('item-20261103', $post->getSourceId());
    }

    // Moved to another content, a post writes its texts not out yet from it again; one with its own text keeps it
    public function testAChangedContentWritesTheTextsAgain(): void
    {
        $publisher = $this->createPublisher([$this->createBrowsableSource()], [$this->createNetwork('bluesky')], written: ['bluesky' => 'Written for 43']);
        $post = new SocialPost('gallery_media', '42', 'Photo 42', 'https://example.org/42', null, new \DateTimeImmutable('+1 day'));
        new SocialPostTarget($post, 'bluesky', 'Written for 42');

        $publisher->redraw($post);
        $this->assertSame('Written for 43', $post->getTargets()->first()->getText());

        $post->setText('Mine');
        $post->getTargets()->first()->setText('Mine');
        $publisher->changeContent($post, '44');
        $this->assertSame('Mine', $post->getTargets()->first()->getText());
    }

    // Chosen, only a content still free is taken; the choices are those still free in the group asked
    public function testAChosenContentMustStillBeFree(): void
    {
        $publisher = $this->createPublisher([$this->createBrowsableSource()], [$this->createNetwork('bluesky')]);
        $post = new SocialPost('gallery_media', '42', 'Photo 42', 'https://example.org/42', null, new \DateTimeImmutable('+1 day'));

        $this->assertFalse($publisher->changeContent($post, '7'));
        $this->assertTrue($publisher->changeContent($post, '44'));
        $this->assertSame(['44', 'https://example.org/44.jpg'], [$post->getSourceId(), $post->getImageUrl()]);

        $publisher->contentChoices($post, '3', 10);
        $this->assertSame(['excluded' => ['7'], 'scopes' => ['3']], $this->asked['gallery_media']);
    }

    // Gone out on a network, a post keeps the content it went out with
    public function testAPostGoneOutKeepsItsContent(): void
    {
        $publisher = $this->createPublisher([$this->createBrowsableSource()], [$this->createNetwork('bluesky')]);
        $post = new SocialPost('gallery_media', '42', 'Photo 42', 'https://example.org/42', null, new \DateTimeImmutable('+1 day'));
        new SocialPostTarget($post, 'bluesky', 'Text')->markPublished('id');

        $this->assertNull($publisher->browsableSourceOf($post));
        $this->assertFalse($publisher->redraw($post));
        $this->assertSame('42', $post->getSourceId());
    }

    // A content given a text of its own, as a series does: each network gets that text cut, the AI never asked, the content keeping its title
    public function testAContentGivenItsOwnTextGivesEachNetworkThatTextCut(): void
    {
        $publisher = $this->createPublisher([], [$this->createNetwork('bluesky')], written: ['bluesky' => 'Written']);
        $post = new SocialPost('gallery_media', '42', 'Fox', 'https://example.org/fox', 'https://example.org/fox.jpg', new \DateTimeImmutable('+1 day'));
        $post->setText('Photo du jour');

        $publisher->addTargets($post, ['bluesky']);

        $this->assertSame(['Photo du jour', 'Fox'], [$post->getTargets()->first()->getText(), $post->getTitle()]);
        $this->assertArrayNotHasKey('writer', $this->asked);
    }

    // Its text changed, every text not out yet is written from it again; unchanged, a text corrected on its own stays
    public function testAWrittenPostTextChangedRewritesTheTextsNotOutYet(): void
    {
        $publisher = $this->createPublisher([], [$this->createNetwork('bluesky'), $this->createNetwork('facebook')]);
        $post = $publisher->createManual(new \DateTimeImmutable('+1 day'));
        $post->setText('First');
        $publisher->rewriteTargets($post);
        $post->getTargets()->first()->setText('Corrected');
        $post->getTargets()->last()->markPublished('id');

        $publisher->rewriteTargets($post);
        $this->assertSame('Corrected', $post->getTargets()->first()->getText());

        $post->setText('Second');
        $publisher->rewriteTargets($post);
        $this->assertSame('Second', $post->getTargets()->first()->getText());
        $this->assertSame('First', $post->getTargets()->last()->getText());
    }

    // A post's own medias go out with it, in their order, at their public address under the site's
    public function testThePostsOwnMediasGoOutWithIt(): void
    {
        $post = new SocialPost(SocialPost::SOURCE_MANUAL, 'abc', 'Hello', '', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'bluesky', 'Hello');
        $post->addMedia(SocialMedia::reference($post, 'medias/a.jpg', 'image/jpeg', 800, 600));
        $post->addMedia(SocialMedia::reference($post, '/medias/b.jpg', 'image/jpeg'));
        $publisher = $this->createPublisher([], [$this->createNetwork('bluesky')]);

        $publisher->publish($post);

        $this->assertSame(['bluesky:https://example.org/medias/a.jpg,https://example.org/medias/b.jpg'], $this->published);
    }

    // A connected network that also does $contract, its limits those of $name, previewed as its text and link
    /** @param class-string $contract */
    private function createFollowingNetwork(string $contract, string $name, int $maxLength, int $maxImages): NetworkPublisherInterface & Stub
    {
        $network = $this->createStubForIntersectionOfInterfaces([NetworkPublisherInterface::class, $contract]);
        $network->method('getName')->willReturn($name);
        $network->method('isConfigured')->willReturn(true);
        $network->method('getMaxLength')->willReturn($maxLength);
        $network->method('getMediaRules')->willReturn(new MediaRules(maxImages: $maxImages, video: true));
        $network->method('preview')->willReturnCallback(static fn (string $text, SocialContent $content): array => ['text' => $text, 'url' => $content->url]);

        return $network;
    }

    // A network answering its posts with a first comment: what it was sent and commented recorded in $this->published, a refused comment thrown while $commentFailures lasts
    private function createCommentingNetwork(int $commentFailures = 0): NetworkPublisherInterface
    {
        $network = $this->createFollowingNetwork(FirstCommentPublisherInterface::class, 'linkedin', 3000, 20);
        $network->method('publish')->willReturnCallback(function (string $text, SocialContent $content): string {
            $this->published[] = 'post:' . $content->url;

            return 'urn:li:share:1';
        });
        $network->method('comment')->willReturnCallback(function (string $postId, string $text) use (&$commentFailures): string {
            if ($commentFailures-- > 0) {
                throw new \RuntimeException('Throttled');
            }
            $this->published[] = 'comment:' . $postId . ':' . $text;

            return '42';
        });

        return $network;
    }

    // A network posting as a Page and sharing it again on the member's profile: what it was sent and reshared recorded in $this->published, a refused reshare thrown while $reshareFailures lasts
    private function createResharingNetwork(int $reshareFailures = 0, bool $page = true, string $postId = 'urn:li:share:1'): NetworkPublisherInterface
    {
        $network = $this->createFollowingNetwork(ProfileResharePublisherInterface::class, 'linkedin', 3000, 20);
        $network->method('canReshare')->willReturn($page);
        $network->method('publish')->willReturnCallback(function (string $text, SocialContent $content) use ($postId): string {
            $this->published[] = 'post:' . $content->url;

            return $postId;
        });
        $network->method('reshare')->willReturnCallback(function (string $postId, string $text) use (&$reshareFailures): string {
            if ($reshareFailures-- > 0) {
                throw new \RuntimeException('Throttled');
            }
            $this->published[] = 'reshare:' . $postId . ':' . $text;

            return 'urn:li:share:2';
        });

        return $network;
    }

    // The reshare goes right after the Page's post, the post keeping its link card
    public function testAReshareFollowsThePost(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'linkedin', 'Text')->setReshare('Our new post');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createResharingNetwork()]);

        $report = $publisher->publish($post);

        $this->assertSame(['post:https://example.org/42', 'reshare:urn:li:share:1:Our new post'], $this->published);
        $this->assertSame(['status' => 'published', 'message' => 'urn:li:share:1'], $report['linkedin']);
        $this->assertSame('urn:li:share:2', $post->getTargets()->first()->getReshareId());
        $this->assertTrue($publisher->supportsReshare('linkedin'));
    }

    // A refused reshare leaves the post published, and sending it again sends the reshare alone
    public function testARefusedReshareKeepsThePostAndOnlyTheReshareIsSentAgain(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'linkedin', 'Text')->setReshare('Our new post');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createResharingNetwork(1)]);

        $report = $publisher->publish($post);
        $this->assertSame(['status' => 'published', 'message' => 'urn:li:share:1', 'reshare_error' => 'Throttled'], $report['linkedin']);

        $report = $publisher->retryFailed($post);

        $this->assertSame(['post:https://example.org/42', 'reshare:urn:li:share:1:Our new post'], $this->published);
        $this->assertSame(['status' => 'published', 'message' => 'urn:li:share:1'], $report['linkedin'] ?? null);
    }

    // A post accepted without its id stays published, never sent again, its reshare said impossible rather than sent on no post
    public function testAPostAcceptedWithoutItsIdIsNotResharedNorSentAgain(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'linkedin', 'Text')->setReshare('Our new post');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createResharingNetwork(postId: '')]);

        $report = $publisher->publish($post);

        $this->assertSame('published', $report['linkedin']['status']);
        $this->assertStringContainsString('cannot be reshared', (string) ($report['linkedin']['reshare_error'] ?? ''));
        $this->assertSame(['post:https://example.org/42'], $this->published);
    }

    // A network back on a profile reshares nothing, the reshare said refused with its reason
    public function testAReshareWithoutPageSaysWhy(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'linkedin', 'Text')->setReshare('Our new post');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createResharingNetwork(page: false)]);

        $report = $publisher->publish($post);

        $this->assertSame('The network no longer posts as a Page.', $report['linkedin']['reshare_error'] ?? null);
        $this->assertSame(['post:https://example.org/42'], $this->published);
        $this->assertFalse($publisher->supportsReshare('linkedin'));
    }

    // A network showing the post as a story too: what it was sent recorded in $this->published, a refused story thrown while $storyFailures lasts
    private function createStoryNetwork(int $storyFailures = 0): NetworkPublisherInterface
    {
        $network = $this->createFollowingNetwork(StoryPublisherInterface::class, 'instagram', 2200, 10);
        $network->method('publish')->willReturnCallback(function (): string {
            $this->published[] = 'post';

            return 'm1';
        });
        $network->method('story')->willReturnCallback(function (SocialContent $content, ?PostMedia $video = null) use (&$storyFailures): string {
            if ($storyFailures-- > 0) {
                throw new \RuntimeException('Throttled');
            }
            $this->published[] = 'story:' . (null === $video ? $content->imagePath . $content->imageUrl : 'video:' . basename($video->path));

            return 's1';
        });

        return $network;
    }

    // The story goes right after the post, with the content's image when the post has none of its own
    public function testAStoryFollowsThePost(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', 'https://example.org/42.jpg', new \DateTimeImmutable());
        new SocialPostTarget($post, 'instagram', 'Text')->setStory(true);
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createStoryNetwork()]);

        $report = $publisher->publish($post);

        $this->assertSame('post', $this->published[0]);
        $this->assertSame('story:/moved.webp', $this->published[1]);
        $this->assertSame(['status' => 'published', 'message' => 'm1'], $report['instagram']);
        $this->assertSame('s1', $post->getTargets()->first()->getStoryId());
        $this->assertTrue($publisher->supportsStory('instagram'));
    }

    // A post carrying a video sends it as the story rather than a picture
    public function testAStoryTakesThePostsVideoFirst(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', 'https://example.org/42.jpg', new \DateTimeImmutable());
        $post->addMedia(SocialMedia::reference($post, '/medias/card.mp4', 'video/mp4', 1080, 1920));
        new SocialPostTarget($post, 'instagram', 'Text')->setStory(true);
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createStoryNetwork()]);

        $publisher->publish($post);

        $this->assertSame('story:video:card.mp4', $this->published[1]);
    }

    // A refused story leaves the post published, and sending it again sends the story alone
    public function testARefusedStoryKeepsThePostAndOnlyTheStoryIsSentAgain(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'instagram', 'Text')->setStory(true);
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createStoryNetwork(1)]);

        $report = $publisher->publish($post);
        $this->assertSame('Throttled', $report['instagram']['story_error'] ?? null);

        $report = $publisher->retryFailed($post);

        $this->assertCount(2, $this->published);
        $this->assertSame(['status' => 'published', 'message' => 'm1'], $report['instagram'] ?? null);
        $this->assertFalse($publisher->supportsStory('linkedin'));
    }

    // A post without any picture can never make a story: the box is unticked with its reason, never left waiting for a retry
    public function testAStoryWithoutPictureIsUntickedWithItsReason(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        $target = new SocialPostTarget($post, 'instagram', 'Text')->setStory(true);
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null, pictureless: true)], [$this->createStoryNetwork()]);

        $publisher->publish($post);

        $this->assertSame(['post'], $this->published);
        $this->assertFalse($target->isStory());
        $this->assertFalse($target->isFollowUpPending());
        $this->assertSame('A story takes a picture, and this post has none.', $target->getStoryError());
    }

    // A source falling back on the site's default image, read from disk or from its address
    private function createDefaultImageSource(?string $imagePath, ?string $imageUrl): SocialContentSourceInterface
    {
        $source = $this->createStub(SocialContentSourceInterface::class);
        $source->method('getSourceType')->willReturn('gallery_media');
        $source->method('getContent')->willReturn(new SocialContent('42', 'Title', 'https://example.org/42', imagePath: $imagePath, imageUrl: $imageUrl));

        return $source;
    }

    // The site's default image says nothing of the post: the box is unticked with no refusal to report, the post going out
    #[DataProvider('defaultImages')]
    public function testADefaultImageUnticksTheStorySilently(?string $imagePath, ?string $imageUrl): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        $target = new SocialPostTarget($post, 'instagram', 'Text')->setStory(true);
        $publisher = $this->createPublisher([$this->createDefaultImageSource($imagePath, $imageUrl)], [$this->createStoryNetwork()]);

        $report = $publisher->publish($post);

        $this->assertSame(['post'], $this->published);
        $this->assertFalse($target->isStory());
        $this->assertNull($target->getStoryError());
        $this->assertArrayNotHasKey('story_error', $report['instagram']);
    }

    /** @return iterable<string, array{0: ?string, 1: ?string}> */
    public static function defaultImages(): iterable
    {
        yield 'from disk' => ['/var/www/public/og-image.webp', 'https://example.org/og-image.webp'];
        yield 'from its address' => [null, 'https://example.org/og-image.webp?v=2'];
    }

    // A video goes out as a story whatever the content's image, and a page's own picture named after it is no default
    public function testADefaultImageLeavesAVideoStoryAndAnOwnPictureAlone(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        $post->addMedia(SocialMedia::reference($post, '/medias/card.mp4', 'video/mp4', 1080, 1920));
        new SocialPostTarget($post, 'instagram', 'Text')->setStory(true);
        $this->createPublisher([$this->createDefaultImageSource(null, 'https://example.org/og-image.webp')], [$this->createStoryNetwork()])->publish($post);
        $this->assertSame('story:video:card.mp4', $this->published[1]);

        $this->published = [];
        $own = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($own, 'instagram', 'Text')->setStory(true);
        $this->createPublisher([$this->createDefaultImageSource(null, 'https://example.org/medias/og-image-item-1.webp')], [$this->createStoryNetwork()])->publish($own);
        $this->assertCount(2, $this->published);
    }

    // The first comment goes right after the post, which leaves its link out so that it goes out as a picture rather than a link card
    public function testAFirstCommentFollowsThePostWhichLeavesItsLinkOut(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'linkedin', 'Text')->setComment('Read more: https://example.org/42');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createCommentingNetwork()]);

        $report = $publisher->publish($post);

        $this->assertSame(['post:', 'comment:urn:li:share:1:Read more: https://example.org/42'], $this->published);
        $this->assertSame(['status' => 'published', 'message' => 'urn:li:share:1'], $report['linkedin']);
        $this->assertSame('42', $post->getTargets()->first()->getCommentId());
    }

    // A refused comment leaves the post published, and sending it again sends the comment alone
    public function testARefusedCommentKeepsThePostAndOnlyTheCommentIsSentAgain(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'linkedin', 'Text')->setComment('Link');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createCommentingNetwork(1)]);

        $report = $publisher->publish($post);
        $this->assertSame(['status' => 'published', 'message' => 'urn:li:share:1', 'comment_error' => 'Throttled'], $report['linkedin']);

        $report = $publisher->retryFailed($post);

        $this->assertSame(['post:', 'comment:urn:li:share:1:Link'], $this->published);
        $this->assertSame(['status' => 'published', 'message' => 'urn:li:share:1'], $report['linkedin'] ?? null);
    }

    // A comment to send again on a network no longer connected says so, rather than waiting with no reason
    public function testACommentOnANetworkNoLongerConnectedSaysWhy(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'linkedin', 'Text')->setComment('Link');
        $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createCommentingNetwork(1)])->publish($post);

        $report = $this->createPublisher([$this->createSource('gallery_media', null)], [])->retryFailed($post);

        $this->assertSame('The network is no longer connected.', $report['linkedin']['comment_error'] ?? null);
        $this->assertSame(['post:'], $this->published);
    }

    // Without a comment, the post keeps its link card; a network taking no comment ignores one
    public function testWithoutCommentOrCommentingNetworkThePostKeepsItsLink(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'linkedin', 'Text');
        new SocialPostTarget($post, 'bluesky', 'Text')->setComment('Ignored');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createCommentingNetwork(), $this->createNetwork('bluesky')]);

        $publisher->publish($post);

        $this->assertSame(['post:https://example.org/42', 'bluesky:/moved.webp'], $this->published);
        $this->assertTrue($publisher->supportsComment('linkedin'));
        $this->assertFalse($publisher->supportsComment('bluesky'));
    }

    // The dry run shows the first comment beside the post it would answer
    public function testADryRunShowsTheFirstComment(): void
    {
        $post = $this->approvedPost(new \DateTimeImmutable('-1 minute'), 'linkedin');
        $post->getTargets()->first()->setComment('Link');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createCommentingNetwork()], approved: [$post]);

        $report = $publisher->publishPlanned(true);

        $this->assertSame(['text' => 'Approved text', 'url' => '', 'first_comment' => 'Link'], array_values($report)[0]['payload']);
        $this->assertSame([], $this->published);
    }

    // The language corrected on the post wins over its content's, which the network then files it under
    public function testThePostsLanguageGoesWithTheContent(): void
    {
        $locales = [];
        $network = $this->createStub(NetworkPublisherInterface::class);
        $network->method('getName')->willReturn('bluesky');
        $network->method('isConfigured')->willReturn(true);
        $network->method('publish')->willReturnCallback(static function (string $text, SocialContent $content) use (&$locales): string {
            $locales[] = $content->locale;

            return 'id';
        });
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'bluesky', 'Text');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$network]);

        $publisher->publish($post->setLocale('en'));

        $this->assertSame(['en'], $locales);
    }

    // A network asking choices post by post: the one withOptions() hands back publishes, with the target's choices recorded in $this->asked
    private function createOptionsNetwork(): NetworkPublisherInterface
    {
        $network = $this->createFollowingNetwork(PostOptionsPublisherInterface::class, 'tiktok', 2200, 0);
        $network->method('getOptionsType')->willReturn('App\\Form\\TikTokOptionsType');
        $network->method('checkOptions')->willReturnCallback(static fn (array $options): array => [] === $options ? [new TranslatableMessage('label.social_tiktok_consent_required', [], 'social')] : []);
        $network->method('withOptions')->willReturnCallback(function (array $options): NetworkPublisherInterface {
            $this->asked['options'] = $options;

            return $this->createNetwork('tiktok');
        });

        return $network;
    }

    // The post goes out with the choices made on its target, through the publisher withOptions() hands back
    public function testANetworkAskingChoicesPublishesWithThem(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'tiktok', 'Text')->setOptions(['privacy' => 'SELF_ONLY', 'consent' => true]);
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createOptionsNetwork()]);

        $report = $publisher->publish($post);

        $this->assertSame(['privacy' => 'SELF_ONLY', 'consent' => true], $this->asked['options']);
        $this->assertSame(['status' => 'published', 'message' => 'tiktok-post-id'], $report['tiktok']);
        $this->assertSame('App\\Form\\TikTokOptionsType', $publisher->getOptionsType('tiktok'));
        $this->assertNull($publisher->getOptionsType('bluesky'));
    }

    // Its choices checked on the target, none asked of a network asking none
    public function testTheChoicesOfATargetAreChecked(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        $tiktok = new SocialPostTarget($post, 'tiktok', 'Text');
        $bluesky = new SocialPostTarget($post, 'bluesky', 'Text');
        $publisher = $this->createPublisher([], [$this->createOptionsNetwork(), $this->createNetwork('bluesky')]);

        $this->assertCount(1, $publisher->checkOptions($tiktok));
        $this->assertSame([], $publisher->checkOptions($bluesky));
        $this->assertSame([], $publisher->checkOptions($tiktok->setOptions(['consent' => true])));
    }

    // Added to an approved post, a network asking choices waits for them, the others approved with the post
    public function testATargetAskingChoicesIsNotApprovedWithThePost(): void
    {
        $post = $this->approvedPost(new \DateTimeImmutable('+1 day'), 'linkedin');
        $publisher = $this->createPublisher([$this->createSource('gallery_media', null)], [$this->createOptionsNetwork(), $this->createNetwork('bluesky')]);

        $publisher->addTargets($post, ['tiktok', 'bluesky']);

        $statuses = [];
        foreach ($post->getTargets() as $target) {
            $statuses[$target->getNetwork()] = $target->getStatus();
        }
        $this->assertSame(['linkedin' => SocialPostStatus::Approved, 'tiktok' => SocialPostStatus::Draft, 'bluesky' => SocialPostStatus::Approved], $statuses);
    }

    // The contents still free: a source listing them lists them, its posted ids left out, one handing them out gives its next; a source not named, or not installed, gives nothing
    public function testTheFreeContentsOfEachSource(): void
    {
        $publisher = $this->createPublisher([$this->createBrowsableSource(), $this->createSource('book', '12')], [$this->createNetwork('bluesky')]);

        $free = $publisher->freeContents([], 10);
        $this->assertSame(['gallery_media:44:3', 'book:12:'], array_map(static fn (array $row): string => $row['source'] . ':' . $row['content']->sourceId . ':' . $row['scope'], $free));
        $this->assertSame(['7'], $this->asked['gallery_media']['excluded']);

        $this->assertSame(['book'], array_column($publisher->freeContents(['book'], 10), 'source'));
        $this->assertSame('Fresh', $publisher->findContent('book', '12')?->title);
        $this->assertNull($publisher->findContent('page', '1'));
    }

    // A content taken is not free, unless the post asking holds it itself; a source not installed has nothing free
    public function testAContentIsFreeUntilTaken(): void
    {
        $publisher = $this->createPublisher([$this->createBrowsableSource()], [$this->createNetwork('bluesky')]);
        $holder = new SocialPost('gallery_media', '7', 'Photo 7', 'https://example.org/7', null, new \DateTimeImmutable('+1 day'));

        $this->assertTrue($publisher->isFree('gallery_media', '44'));
        $this->assertFalse($publisher->isFree('gallery_media', '7'));
        $this->assertTrue($publisher->isFree('gallery_media', '7', $holder));
        $this->assertFalse($publisher->isFree('page', '1'));
    }

    // A content is held outside a series by a post of another one still to come; gone out, past, or of the series asking, it is not
    public function testAContentIsHeldOutsideByAPostToComeOfAnotherSeries(): void
    {
        $series = new SocialSeries('Teasers', 3, 'daily', 1, [], 'text', 'Text', [], ['bluesky']);
        $coming = new SocialPost('book', '8', 'Book 8', 'https://example.org/8', null, new \DateTimeImmutable('+1 day'));
        $sent = new SocialPost('book', '8', 'Book 8', 'https://example.org/8', null, new \DateTimeImmutable('+1 day'));
        new SocialPostTarget($sent, 'bluesky', '')->markPublished('id');
        $past = new SocialPost('book', '8', 'Book 8', 'https://example.org/8', null, new \DateTimeImmutable('-1 day'));

        $this->assertTrue($this->createPublisher([], [], holders: [$coming])->isHeldOutside('book', '8', null));
        $this->assertTrue($this->createPublisher([], [], holders: [$coming])->isHeldOutside('book', '8', $series));
        $coming->setSeries($series);
        $this->assertFalse($this->createPublisher([], [], holders: [$coming])->isHeldOutside('book', '8', $series));
        $this->assertFalse($this->createPublisher([], [], holders: [$sent, $past])->isHeldOutside('book', '8', null));
    }

    // A target naming an account reposts its post rather than publishing its text
    public function testARepostGoesOutInsteadOfTheText(): void
    {
        $post = new SocialPost(SocialPost::SOURCE_MANUAL, 'id', 'Title', 'https://example.org/42', null, new \DateTimeImmutable());
        new SocialPostTarget($post, 'bluesky', 'Label')->setRepost('other.bsky.social');
        $network = $this->createStubForIntersectionOfInterfaces([NetworkPublisherInterface::class, RepostPublisherInterface::class]);
        $network->method('getName')->willReturn('bluesky');
        $network->method('isConfigured')->willReturn(true);
        $network->method('canRepost')->willReturn(true);
        $network->method('repost')->willReturnCallback(static fn (string $account, string $url): string => $account . '|' . $url);
        $network->method('publish')->willThrowException(new \LogicException('A repost publishes nothing.'));

        $publisher = $this->createPublisher([], [$network]);
        $report = $publisher->publish($post);

        $this->assertSame(['status' => 'published', 'message' => 'other.bsky.social|https://example.org/42'], $report['bluesky']);
        $this->assertTrue($publisher->supportsRepost('bluesky'));
        $this->assertFalse($publisher->supportsRepost('linkedin'));
    }
}
