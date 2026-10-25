<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Controller\Management\SocialCalendarController;
use c975L\SocialBundle\Entity\SocialMedia;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Enum\SocialPostStatus;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Service\SocialMediaChecker;
use c975L\SocialBundle\Service\SocialMediaPicker;
use c975L\SocialBundle\Service\SocialPlanner;
use c975L\SocialBundle\Service\SocialPostApprover;
use c975L\SocialBundle\Service\SocialPublisher;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

// What a card dropped on the calendar, or the panel's buttons, do to its post: the move is the one thing this screen writes
class SocialCalendarControllerTest extends TestCase
{
    // The media checker the next controller built is given, a stub answering no problem otherwise
    private ?SocialMediaChecker $mediaChecker = null;

    private SocialPost $post;

    private \DateTimeImmutable $plannedAt;

    private int $flushes = 0;

    // The limit every network's text is held to, none otherwise
    private ?int $maxLength = null;

    // What the repository answers as the series' coming posts
    /** @var list<SocialPost> */
    private array $seriesPosts = [];

    /** @var array<string, mixed> */
    private array $rendered = [];

    protected function setUp(): void
    {
        $this->plannedAt = new \DateTimeImmutable('+1 day 09:30');
        $this->post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, $this->plannedAt);
        new SocialPostTarget($this->post, 'bluesky', 'Text');
    }

    private function controller(?SocialPostRepository $postRepository = null): SocialCalendarController
    {
        return new SocialCalendarController(
            $this->createStub(ConfigServiceInterface::class),
            new SocialPlanner(),
            $this->createStub(SocialPublisher::class),
            $postRepository ?? $this->createStub(SocialPostRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AdminUrlGeneratorInterface::class),
            $this->mediaChecker ?? $this->createStub(SocialMediaChecker::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(SocialMediaPicker::class),
            $this->createStub(SocialPostApprover::class),
        );
    }

    // A card covers two quarters of an hour: items whose heights overlap go side by side, the next one free again taking the first lane back
    public function testOverlappingItemsOfTheWeekGoSideBySide(): void
    {
        $item = static fn (string $kind, string $at): array => ['kind' => $kind, 'at' => new \DateTimeImmutable('2026-10-04 ' . $at)];
        $rows = new \ReflectionMethod(SocialCalendarController::class, 'rows')->invoke($this->controller(), [
            $item('published', '19:00'), $item('planned', '19:15'), $item('planned', '19:30'), $item('planned', '21:00'),
        ]);

        // From 06:00, 19:00 is the 52nd quarter of an hour
        $this->assertSame([[52, 0], [53, 1], [54, 0], [60, 0]], array_map(static fn (array $group): array => [$group['row'], $group['lane']], $rows));
        $this->assertSame([2], array_values(array_unique(array_column($rows, 'lanes'))));
    }

    // A network sent more than a quarter of an hour after the others gets its own card, the ones gone out in one run sharing theirs
    public function testNetworksGoneOutAtDifferentMomentsGetTheirOwnCards(): void
    {
        $this->assertSame(
            [['12:07', ['facebook', 'instagram']], ['16:02', ['bluesky']]],
            $this->publishedCards(['bluesky' => '16:02', 'instagram' => '12:08', 'facebook' => '12:07'], false),
        );
    }

    // The month has no hour to tell two cards apart: a post gets one card per day, its networks all on it
    public function testTheMonthGathersADaysNetworksOnOneCard(): void
    {
        $this->assertSame(
            [['12:00', ['facebook', 'instagram', 'bluesky']]],
            $this->publishedCards(['bluesky' => '16:02', 'facebook' => '12:00', 'instagram' => '12:01'], true),
        );
    }

    // The published cards of the 13th, each as its moment and its networks, the post gone out on $networks at their moments
    /**
     * @param array<string, string> $networks
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private function publishedCards(array $networks, bool $byDay): array
    {
        $publishedAt = new \ReflectionProperty(SocialPostTarget::class, 'publishedAt');
        foreach ($networks as $network => $at) {
            $target = 'bluesky' === $network ? $this->target() : new SocialPostTarget($this->post, $network, 'Text');
            $target->markPublished('ext-' . $network);
            $publishedAt->setValue($target, new \DateTimeImmutable('2026-10-13 ' . $at));
        }

        $postRepository = $this->createStub(SocialPostRepository::class);
        $postRepository->method('findPublishedBetween')->willReturn([$this->post]);

        $items = new \ReflectionMethod(SocialCalendarController::class, 'publishedItems')->invoke($this->controller($postRepository), new \DateTimeImmutable('2026-10-12'), new \DateTimeImmutable('2026-10-19'), $byDay);
        $this->assertSame(['2026-10-13'], array_keys($items));

        $cards = array_values($items['2026-10-13']);
        usort($cards, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return array_map(static fn (array $item): array => [$item['at']->format('H:i'), $item['networks']], $cards);
    }

    // The card's colour: a draft, an approved post, one refused somewhere, one gone out on every network
    public function testTheStateOfAPostSaysWhereItStands(): void
    {
        $state = fn (): string => $this->post->getState();

        $this->assertSame('draft', $state());
        $this->post->approve();
        $this->assertSame('approved', $state());
        $this->target()->markFailed('Refused');
        $this->assertSame('failed', $state());
        $this->target()->markPublished('ext-1');
        $this->assertSame('published', $state());
    }

    // A post with no network has gone nowhere: a draft, still shown and still moved
    public function testAPostWithNoNetworkIsADraft(): void
    {
        $post = new SocialPost(SocialPost::SOURCE_MANUAL, 'none', 'None', '', null, new \DateTimeImmutable('+1 day'));

        $this->assertSame('draft', $post->getState());
    }

    // Same seam as the OAuth controllers' tests: AbstractController resolves security and the csrf check through its container
    private function createController(Request $request): SocialCalendarController
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key) => 'site-role-editor' === $key ? 'ROLE_EDITOR' : null);

        $postRepository = $this->createStub(SocialPostRepository::class);
        $postRepository->method('find')->willReturnCallback(fn (mixed $id): ?SocialPost => 42 === $id ? $this->post : null);
        $postRepository->method('findComingBySeries')->willReturnCallback(fn (): array => $this->seriesPosts);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        $publisher = $this->createStub(SocialPublisher::class);
        $publisher->method('getMaxLength')->willReturnCallback(fn (): ?int => $this->maxLength);

        // A network's problem written the way French puts it, any other message read as its key
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $parameters = []): string => 'label.social_calendar_text_refused' === $id ? strtr('%network% : %reason%', $parameters) : $id);

        $controller = new SocialCalendarController(
            $configService,
            new SocialPlanner(),
            $publisher,
            $postRepository,
            $entityManager,
            $this->createStub(AdminUrlGeneratorInterface::class),
            $this->mediaChecker ?? $this->createStub(SocialMediaChecker::class),
            $translator,
            $this->createStub(SocialMediaPicker::class),
            new SocialPostApprover($this->mediaChecker ?? $this->createStub(SocialMediaChecker::class), $entityManager),
        );

        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn(true);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $csrfToken): bool => 'valid' === $csrfToken->getValue());
        // The panel answered is what the template was handed, kept to be read
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $view, array $parameters): string {
            $this->rendered = $parameters;

            return 'panel';
        });
        $services = ['request_stack' => new RequestStack([$request]), 'security.authorization_checker' => $authorizationChecker, 'security.csrf.token_manager' => $csrf, 'twig' => $twig, 'router' => $this->createStub(UrlGeneratorInterface::class)];
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => isset($services[$id]));
        $container->method('get')->willReturnCallback(static fn (string $id) => $services[$id] ?? null);
        $controller->setContainer($container);

        return $controller;
    }

    private function move(string $to, string $token = 'valid'): Response
    {
        $request = Request::create('/', 'POST', ['post' => '42', 'to' => $to]);
        $request->headers->set('X-CSRF-Token', $token);

        return $this->createController($request)->move($request);
    }

    private function target(): SocialPostTarget
    {
        $target = $this->post->getTargets()->first();
        \assert($target instanceof SocialPostTarget);

        return $target;
    }

    // The day: the week's grid on that day alone, with its quarters of an hour, the arrows moving a day at a time
    public function testTheDayViewShowsOneDayOfQuartersOfAnHour(): void
    {
        $request = Request::create('/', 'GET', ['view' => 'day', 'date' => '2026-10-22']);
        $this->createController($request)->index($request);

        $this->assertSame('day', $this->rendered['view']);
        $this->assertSame(['2026-10-21', '2026-10-23', '2026-10-22'], [$this->rendered['previous'], $this->rendered['next'], $this->rendered['date']]);
        $this->assertCount(1, $this->rendered['weeks']);
        $this->assertCount(1, $this->rendered['weeks'][0]);
        $this->assertNotEmpty($this->rendered['weeks'][0][0]['cells']);
        $this->assertNotEmpty($this->rendered['hours']);
    }

    // "Day" opens on today when the period shown holds it, on the period's first day otherwise
    public function testTheDayButtonOpensTodayWhenShown(): void
    {
        $request = Request::create('/', 'GET', ['view' => 'month']);
        $this->createController($request)->index($request);
        $this->assertSame(new \DateTimeImmutable('today')->format('Y-m-d'), $this->rendered['day_date']);

        $request = Request::create('/', 'GET', ['view' => 'week', 'date' => '2020-01-08']);
        $this->createController($request)->index($request);
        $this->assertSame('2020-01-06', $this->rendered['day_date']);
    }

    // A draft dropped on a quarter of an hour is planned there, the moment brought to the server's own time zone, and stays a draft
    public function testADraftDroppedOnAMomentIsPlannedThereAndStaysADraft(): void
    {
        $at = new \DateTimeImmutable('+2 days 19:00');

        $this->assertSame(Response::HTTP_NO_CONTENT, $this->move($at->format('c'))->getStatusCode());
        $this->assertSame(SocialPostStatus::Draft, $this->target()->getStatus());
        $this->assertEquals($at, $this->post->getPlannedAt());
        $this->assertSame(1, $this->flushes);
    }

    // An approved post moved elsewhere keeps its approval: only the panel's button takes it back
    public function testAnApprovedPostMovedStaysApproved(): void
    {
        $this->post->approve();

        $this->move(new \DateTimeImmutable('+3 days 08:00')->format('c'));

        $this->assertSame(SocialPostStatus::Approved, $this->target()->getStatus());
    }

    // Dropped on a day of the month, a post keeps its time of day
    public function testADayOnlyDropKeepsTheTimeOfDay(): void
    {
        $day = new \DateTimeImmutable('+5 days');

        $this->assertSame(Response::HTTP_NO_CONTENT, $this->move($day->format('Y-m-d'))->getStatusCode());
        $this->assertEquals($day->setTime(9, 30), $this->post->getPlannedAt());
    }

    // Today dropped on with the post's time of day already gone by would only send it at once
    public function testADayOnlyDropOnTodayWhenItsTimeIsGoneIsRefused(): void
    {
        $this->post->setPlannedAt(new \DateTimeImmutable('today 00:00'));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->move(new \DateTimeImmutable('today')->format('Y-m-d'))->getStatusCode());
        $this->assertSame(0, $this->flushes);
    }

    // The panel's field, typed in the server's time zone, is read there and brought to the nearest quarter of an hour; a drop on a quarter stays exact
    public function testAMomentTypedInThePanelIsReadInTheServerTimeZoneToTheQuarter(): void
    {
        $day = new \DateTimeImmutable('+2 days');

        $this->move($day->format('Y-m-d') . 'T10:08');
        $this->assertEquals($day->setTime(10, 15), $this->post->getPlannedAt());

        $this->move($day->setTime(10, 7)->format('c'));
        $this->assertEquals($day->setTime(10, 7), $this->post->getPlannedAt());
    }

    // A media a network does not take refuses the approval, the reason said in the answer for the calendar to show
    public function testAnApprovalTheMediasDoNotSuitIsRefusedWithItsReason(): void
    {
        $this->mediaChecker = $this->createStub(SocialMediaChecker::class);
        $this->mediaChecker->method('check')->willReturn(['instagram' => [new TranslatableMessage('label.social_media_required')]]);

        $response = $this->move('approve');

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertStringStartsWith('Instagram : ', (string) $response->getContent());
        $this->assertSame(SocialPostStatus::Draft, $this->target()->getStatus());
        $this->assertSame(0, $this->flushes);
    }

    // The panel's approval goes through the move, the post keeping its moment
    public function testApproveAndUnapproveGoThroughTheMove(): void
    {
        $this->assertSame(Response::HTTP_NO_CONTENT, $this->move('approve')->getStatusCode());
        $this->assertSame(SocialPostStatus::Approved, $this->target()->getStatus());

        $this->assertSame(Response::HTTP_NO_CONTENT, $this->move('unapprove')->getStatusCode());
        $this->assertSame(SocialPostStatus::Draft, $this->target()->getStatus());
        $this->assertEquals($this->plannedAt, $this->post->getPlannedAt());
        $this->assertSame(2, $this->flushes);
    }

    // A moment already gone would only be sent at once, somewhere it was not dropped; an unknown place plans nothing
    public function testAMomentGoneOrAnUnknownPlaceIsRefused(): void
    {
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->move(new \DateTimeImmutable('-1 hour')->format('c'))->getStatusCode());
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->move('nowhere')->getStatusCode());
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->move('')->getStatusCode());
        $this->assertEquals($this->plannedAt, $this->post->getPlannedAt());
        $this->assertSame(0, $this->flushes);
    }

    // A post gone out on every network moves no more
    public function testAPublishedPostIsRefused(): void
    {
        $this->target()->markPublished('ext-1');

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->move(new \DateTimeImmutable('+3 days 08:00')->format('c'))->getStatusCode());
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->move('unapprove')->getStatusCode());
        $this->assertEquals($this->plannedAt, $this->post->getPlannedAt());
        $this->assertSame(0, $this->flushes);
    }

    // A post refused somewhere is still moved, to be tried again at its new moment
    public function testAFailedPostCanBeMoved(): void
    {
        $this->target()->markFailed('Refused');
        $at = new \DateTimeImmutable('+3 days 08:00');

        $this->assertSame(Response::HTTP_NO_CONTENT, $this->move($at->format('c'))->getStatusCode());
        $this->assertEquals($at, $this->post->getPlannedAt());
        $this->assertSame(SocialPostStatus::Failed, $this->target()->getStatus());
    }

    // The post's medias with the ids a saved post gives them, in the order given
    private function addMedias(int ...$ids): void
    {
        foreach ($ids as $position => $id) {
            $media = new SocialMedia($this->post)->setFilename('social/' . $id . '.jpg')->setMimeType('image/jpeg')->setPosition($position);
            new \ReflectionProperty(SocialMedia::class, 'id')->setValue($media, $id);
            $this->post->addMedia($media);
        }
    }

    /** @param array<string, mixed> $parameters */
    private function medias(array $parameters, string $token = 'valid'): Response
    {
        $request = Request::create('/', 'POST', ['post' => '42'] + $parameters);
        $request->headers->set('X-CSRF-Token', $token);

        return $this->createController($request)->medias($request);
    }

    /** @return list<int|null> */
    private function renderedMediaIds(): array
    {
        return array_map(static fn (SocialMedia $media): ?int => $media->getId(), $this->rendered['medias']->getValues());
    }

    // The medias dragged in the panel take the order sent, one it did not name going last - the panel answered reading them so, its picture the first of them
    public function testTheMediasTakeTheOrderSent(): void
    {
        $this->addMedias(1, 2, 3);

        // The order alone is sent with "remove" left empty by a browser that names it
        $this->assertSame(Response::HTTP_OK, $this->medias(['remove' => '', 'medias' => ['3', '1']])->getStatusCode());
        $this->assertSame([3, 1, 2], $this->renderedMediaIds());
        $this->assertSame([0, 1, 2], array_map(static fn (SocialMedia $media): int => $media->getPosition(), $this->rendered['medias']->getValues()));
        $this->assertSame('/social/3.jpg', $this->rendered['post']['image']);
        $this->assertTrue($this->rendered['editable']);
        $this->assertSame(1, $this->flushes);
    }

    // A media taken off leaves the post, the others closing the gap
    public function testAMediaIsTakenOffThePost(): void
    {
        $this->addMedias(1, 2, 3);

        $this->medias(['remove' => '2']);

        $this->assertSame([1, 3], $this->renderedMediaIds());
        $this->assertCount(2, $this->post->getMedias());
        $this->assertSame([0, 1], array_map(static fn (SocialMedia $media): int => $media->getPosition(), $this->rendered['medias']->getValues()));
    }

    // The networks show what the post went out with, and a forged request changes nothing
    public function testThePublishedPostAndTheForgedRequestKeepTheirMedias(): void
    {
        $this->addMedias(1, 2);

        $this->assertSame(Response::HTTP_FORBIDDEN, $this->medias(['remove' => '1'], 'forged')->getStatusCode());
        $this->target()->markPublished('ext-1');
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->medias(['remove' => '1'])->getStatusCode());
        $this->assertCount(2, $this->post->getMedias());
        $this->assertSame(0, $this->flushes);
    }

    // Gone out on one network and still waited for on another, a post keeps the medias it went out with - its panel saying so
    public function testAPostGoneOutOnOneNetworkKeepsItsMedias(): void
    {
        $this->addMedias(1, 2);
        new SocialPostTarget($this->post, 'linkedin', 'Le texte de LinkedIn');
        $this->target()->markPublished('ext-1');

        $this->assertNotSame('published', $this->post->getState());
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->medias(['remove' => '1'])->getStatusCode());
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->medias(['medias' => ['2', '1']])->getStatusCode());
        $this->assertCount(2, $this->post->getMedias());
        $this->assertSame(0, $this->flushes);

        $request = Request::create('/', 'GET', ['id' => '42']);
        $this->createController($request)->panel($request);
        $this->assertFalse($this->rendered['editable']);
    }

    // The move plans a post that goes out under the site's name: no token, nothing changes
    public function testAMoveWithoutTheTokenIsRefused(): void
    {
        $this->assertSame(Response::HTTP_FORBIDDEN, $this->move('approve', 'forged')->getStatusCode());
        $this->assertSame(SocialPostStatus::Draft, $this->target()->getStatus());
    }

    // The series' coming drafts approved at once, a post gone out on its network left as it is
    public function testTheSeriesComingDraftsAreApprovedAtOnce(): void
    {
        $other = $this->seriesPost('Other');
        $published = $this->seriesPost('Published');
        $target = $published->getTargets()->first();
        \assert($target instanceof SocialPostTarget);
        $target->markPublished('remote-id');

        $this->assertSame(Response::HTTP_NO_CONTENT, $this->move('approve-series')->getStatusCode());
        $this->assertSame(SocialPostStatus::Approved, $this->target()->getStatus());
        $this->assertSame('approved', $other->getState());
        $this->assertSame('published', $published->getState());
        $this->assertSame(1, $this->flushes);
    }

    // A draft whose medias a network does not take stays a draft, said under its title, the others approved all the same
    public function testADraftOfTheSeriesTheMediasDoNotSuitStaysADraft(): void
    {
        $other = $this->seriesPost('Other');
        $this->mediaChecker = $this->createStub(SocialMediaChecker::class);
        $this->mediaChecker->method('check')->willReturnCallback(static fn (SocialPost $post): array => $post === $other ? ['instagram' => [new TranslatableMessage('label.social_media_required')]] : []);

        $response = $this->move('approve-series');

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertStringStartsWith("Other\nInstagram : ", (string) $response->getContent());
        $this->assertSame('draft', $other->getState());
        $this->assertSame(SocialPostStatus::Approved, $this->target()->getStatus());
        $this->assertSame(1, $this->flushes);
    }

    // Offered on any post of the series: the panel of one gone out approves the others all the same
    public function testThePanelOfAPublishedPostApprovesItsSeries(): void
    {
        $other = $this->seriesPost('Other');
        $this->target()->markPublished('ext-1');

        $this->assertSame(Response::HTTP_NO_CONTENT, $this->move('approve-series')->getStatusCode());
        $this->assertSame('approved', $other->getState());
        $this->assertSame('published', $this->post->getState());
    }

    // A post a network refused is no draft: only its own "Approve" tries it again
    public function testAFailedPostOfTheSeriesIsLeftAsItIs(): void
    {
        $other = $this->seriesPost('Other');
        $this->target()->markFailed('Refused');

        $this->assertSame(Response::HTTP_NO_CONTENT, $this->move('approve-series')->getStatusCode());
        $this->assertSame('approved', $other->getState());
        $this->assertSame(SocialPostStatus::Failed, $this->target()->getStatus());
    }

    // A post of no series approves nothing
    public function testAPostOfNoSeriesApprovesNothing(): void
    {
        $this->assertSame(Response::HTTP_NO_CONTENT, $this->move('approve-series')->getStatusCode());
        $this->assertSame(SocialPostStatus::Draft, $this->target()->getStatus());
    }

    // A post of the same series as $this->post, both of them answered as its coming posts
    private function seriesPost(string $title): SocialPost
    {
        $series = $this->post->getSeries() ?? new SocialSeries('Series', 3, 'daily', 1, [], 'text', 'Text', [], ['bluesky']);
        $this->post->setSeries($series);
        $post = new SocialPost('gallery_media', $title, $title, 'https://example.org/' . $title, null, $this->plannedAt->modify('+1 day'));
        $post->setSeries($series);
        new SocialPostTarget($post, 'bluesky', 'Text');
        $this->seriesPosts = [...([] === $this->seriesPosts ? [$this->post] : $this->seriesPosts), $post];

        return $post;
    }

    /** @param array<int|string, string> $texts */
    private function texts(array $texts, string $token = 'valid'): Response
    {
        $request = Request::create('/', 'POST', ['post' => '42', 'texts' => $texts]);
        $request->headers->set('X-CSRF-Token', $token);

        return $this->createController($request)->texts($request);
    }

    // The post's targets given the ids the panel names them by, a second network added
    private function addTargetIds(): SocialPostTarget
    {
        $linkedin = new SocialPostTarget($this->post, 'linkedin', 'Le texte de LinkedIn');
        new \ReflectionProperty(SocialPostTarget::class, 'id')->setValue($this->target(), 1);
        new \ReflectionProperty(SocialPostTarget::class, 'id')->setValue($linkedin, 2);

        return $linkedin;
    }

    // The texts corrected in the panel are saved together, trimmed, the panel answered as it now reads
    public function testTheTextsCorrectedInThePanelAreSaved(): void
    {
        $linkedin = $this->addTargetIds();

        $this->assertSame(Response::HTTP_OK, $this->texts(['1' => " Nouveau texte \n", '2' => 'Autre texte'])->getStatusCode());
        $this->assertSame('Nouveau texte', $this->target()->getText());
        $this->assertSame('Autre texte', $linkedin->getText());
        $this->assertSame(1, $this->flushes);
        $this->assertSame([null, null], $this->rendered['max_lengths']);
        $this->assertTrue($this->rendered['texts_saved']);
    }

    // A text sent as anything but a string is left as it was, the others saved
    public function testATextSentAsAnArrayIsLeftAsItWas(): void
    {
        $linkedin = $this->addTargetIds();
        $request = Request::create('/', 'POST', ['post' => '42', 'texts' => ['1' => ['Nouveau texte'], '2' => 'Autre texte']]);
        $request->headers->set('X-CSRF-Token', 'valid');

        $this->assertSame(Response::HTTP_OK, $this->createController($request)->texts($request)->getStatusCode());
        $this->assertSame(['Text', 'Autre texte'], [$this->target()->getText(), $linkedin->getText()]);
    }

    // An empty text, or one past its network's limit, refuses the whole save - the other text left as it was
    public function testAnEmptyOrTooLongTextRefusesTheSave(): void
    {
        $linkedin = $this->addTargetIds();

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->texts(['1' => 'Nouveau texte', '2' => '  '])->getStatusCode());
        $this->maxLength = 5;
        $response = $this->texts(['1' => 'Nouveau texte']);

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertStringContainsString('Bluesky : label.social_post_text_help', (string) $response->getContent());
        $this->assertSame(['Text', 'Le texte de LinkedIn'], [$this->target()->getText(), $linkedin->getText()]);
        $this->assertSame(0, $this->flushes);
    }

    // A text gone out on its network keeps what it went out with, and a forged request changes nothing
    public function testAPublishedTextAndAForgedRequestChangeNothing(): void
    {
        $this->addTargetIds();

        $this->assertSame(Response::HTTP_FORBIDDEN, $this->texts(['1' => 'Nouveau texte'], 'forged')->getStatusCode());
        $this->target()->markPublished('ext-1');
        $this->texts(['1' => 'Nouveau texte']);

        $this->assertSame('Text', $this->target()->getText());
    }
}
