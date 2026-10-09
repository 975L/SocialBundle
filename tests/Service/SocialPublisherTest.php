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
use c975L\SocialBundle\Contract\NetworkPublisherInterface;
use c975L\SocialBundle\Entity\SocialMedia;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Enum\SocialPostStatus;
use c975L\SocialBundle\Model\PostMedia;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Service\SocialPageReader;
use c975L\SocialBundle\Service\SocialPostTextBuilder;
use c975L\SocialBundle\Service\SocialPostWriter;
use c975L\SocialBundle\Service\SocialPublisher;
use c975L\UiBundle\Contract\BrowsableSocialContentSourceInterface;
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

    /** @var array<string, array{excluded?: list<string>, since?: ?\DateTimeImmutable, scopes?: list<string>, networks?: list<string>}> */
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

    private function createNetwork(string $name, bool $configured = true, ?\Throwable $failure = null): NetworkPublisherInterface
    {
        $network = $this->createStub(NetworkPublisherInterface::class);
        $network->method('getName')->willReturn($name);
        $network->method('isConfigured')->willReturn($configured);
        $network->method('getMaxLength')->willReturn(300);
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
     */
    private function createPublisher(array $sources, array $networks, array $lastBySourceType = [], string $page = '', array $approved = [], array $written = []): SocialPublisher
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap([
            ['social-publish-template', null],
        ]);

        $repository = $this->createStub(SocialPostRepository::class);
        $repository->method('findLastCreatedAtBySourceType')->willReturn($lastBySourceType);
        $repository->method('findApproved')->willReturn($approved);
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

        $report = $publisher->prepareUrl('https://example.org/histoires/loup', $at);

        $this->assertSame(['bluesky' => ['status' => 'draft', 'message' => '']], $report);
        $post = $this->preparedPost();
        $this->assertSame(SocialPost::SOURCE_URL, $post->getSourceType());
        $this->assertSame(sha1('https://example.org/histoires/loup'), $post->getSourceId());
        $this->assertSame('https://example.org/loup.png', $post->getImageUrl());
        $this->assertEquals($at, $post->getPlannedAt());
        $this->assertSame(SocialPostStatus::Draft, $post->getTargets()->first()->getStatus());
        $this->assertSame([], $this->published);
    }

    // Its drafts would have no network to go out on
    public function testAPageIsNotPreparedWhileNoNetworkIsConnected(): void
    {
        $this->expectExceptionMessageIsOrContains('No network is connected yet');

        $this->createPublisher([], [$this->createNetwork('bluesky', configured: false)])->prepareUrl('https://example.org/page', new \DateTimeImmutable('+1 day'));
    }

    // What makes a dry run safe to try before any credential is plugged in: nothing written, nothing sent, the AI not asked, the template's payload shown
    public function testADryRunShowsThePayloadAndWritesAndSendsNothing(): void
    {
        $page = '<html><head><meta property="og:title" content="Le loup"></head></html>';
        $publisher = $this->createPublisher([], [$this->createNetwork('bluesky')], page: $page, written: ['bluesky' => 'Written by the AI']);

        $report = $publisher->prepareUrl('https://example.org/loup', new \DateTimeImmutable('+1 day'), true);

        $this->assertSame(SocialPublisher::DRY_RUN, $report['bluesky']['status']);
        $this->assertSame("Le loup\n\nhttps://example.org/loup", $report['bluesky']['payload']['text']);
        $this->assertSame([], $this->persisted);
        $this->assertSame([], $this->published);
        $this->assertArrayNotHasKey('writer', $this->asked);
    }

    // The dry run is read before any credential is plugged in, so it shows the networks not configured yet too
    public function testADryRunShowsTheNetworksNotConfiguredYet(): void
    {
        $publisher = $this->createPublisher([], [$this->createNetwork('bluesky', configured: false)], page: '<html><head><meta property="og:title" content="Page"></head></html>');

        $report = $publisher->prepareUrl('https://example.org/page', new \DateTimeImmutable('+1 day'), true);

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

    // A post written on its screen is planned at the moment given and ticked on every network connected, its texts written from its own once saved
    public function testAWrittenPostIsTickedOnEveryNetworkConnected(): void
    {
        $at = new \DateTimeImmutable('2026-10-06 09:15');
        $publisher = $this->createPublisher([], [$this->createNetwork('bluesky'), $this->createNetwork('linkedin', configured: false)]);

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
}
