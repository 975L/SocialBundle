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
use c975L\SocialBundle\Repository\SocialSeriesRepository;
use c975L\SocialBundle\Service\SocialMediaChecker;
use c975L\SocialBundle\Service\SocialMediaPicker;
use c975L\SocialBundle\Service\SocialPlanner;
use c975L\SocialBundle\Service\SocialPostApprover;
use c975L\SocialBundle\Service\SocialPostDuplicator;
use c975L\SocialBundle\Service\SocialPublisher;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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

    // What the repository answers as the ids of the series' coming posts, in their order
    /** @var list<int> */
    private array $seriesIds = [];

    /** @var array<string, mixed> */
    private array $rendered = [];

    // The networks the site is connected to
    /** @var list<string> */
    private array $connected = ['bluesky', 'linkedin'];

    // The networks handed to SocialPublisher::addTargets(), to have their texts written
    /** @var list<string> */
    private array $added = [];

    // What the repository answers: every series with the moment of its last post, ended or not
    /** @var list<array{series: SocialSeries, lastPlannedAt: \DateTimeImmutable}> */
    private array $seriesRows = [];

    // What the entity manager was handed to persist
    /** @var list<object> */
    private array $persisted = [];

    // The networks SocialPublisher::retryFailed() was asked for, and what it answers - null while another "Publish" holds the post
    /** @var list<?string> */
    private array $retried = [];

    /** @var ?array<string, array<string, mixed>> */
    private ?array $retryReport = ['bluesky' => ['ok' => true]];

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
            new SocialPostDuplicator($this->createStub(SocialPublisher::class), sys_get_temp_dir()),
            $this->createStub(SocialSeriesRepository::class),
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
        $postRepository->method('findComingIdsBySeries')->willReturnCallback(fn (): array => $this->seriesIds);

        // The series of the post, known by the id 3
        $seriesRepository = $this->createStub(SocialSeriesRepository::class);
        $seriesRepository->method('find')->willReturnCallback(fn (mixed $id): ?SocialSeries => 3 === $id ? $this->post->getSeries() : null);
        $seriesRepository->method('findWithLastPlannedAt')->willReturnCallback(fn (): array => $this->seriesRows);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        $publisher = $this->createStub(SocialPublisher::class);
        $publisher->method('getMaxLength')->willReturnCallback(fn (): ?int => $this->maxLength);
        $publisher->method('getNetworkNames')->willReturn(['bluesky', 'facebook', 'linkedin']);
        $publisher->method('getConnectedNetworkNames')->willReturnCallback(fn (): array => $this->connected);
        $publisher->method('retryFailed')->willReturnCallback(function (SocialPost $post, ?string $network): ?array {
            $this->retried[] = $network;

            return $this->retryReport;
        });
        $publisher->method('addTargets')->willReturnCallback(function (SocialPost $post, array $networks): void {
            $this->added = $networks;
            foreach ($networks as $network) {
                new SocialPostTarget($post, $network, 'Written');
            }
        });

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
            new SocialPostDuplicator($this->createStub(SocialPublisher::class), sys_get_temp_dir()),
            $seriesRepository,
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
        // A route answered by its name, a redirection needing an address that is not empty
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name): string => '/' . $name);
        $services = ['request_stack' => new RequestStack([$request]), 'security.authorization_checker' => $authorizationChecker, 'security.csrf.token_manager' => $csrf, 'twig' => $twig, 'router' => $router];
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

    /** @param list<UploadedFile> $files */
    private function upload(array $files, string $token = 'valid'): Response
    {
        $request = Request::create('/', 'POST', ['post' => '42'], [], ['files' => $files]);
        $request->headers->set('X-CSRF-Token', $token);

        return $this->createController($request)->upload($request);
    }

    // A file of the given content under a client name, as the browser sends it
    private function uploaded(string $name, string $content): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'social');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    // A small JPEG, which the server recognises as one whatever its name
    private function jpeg(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    // The files dropped on the panel go after the post's medias, in the order sent, the panel answered with them
    public function testTheFilesDroppedAreAddedAfterTheMedias(): void
    {
        $this->addMedias(1);

        $this->assertSame(Response::HTTP_OK, $this->upload([$this->uploaded('a.jpg', $this->jpeg()), $this->uploaded('b.jpg', $this->jpeg())])->getStatusCode());
        $medias = $this->rendered['medias']->getValues();
        $this->assertCount(3, $medias);
        $this->assertSame([0, 1, 2], array_map(static fn (SocialMedia $media): int => $media->getPosition(), $medias));
        $this->assertSame(['a.jpg', 'b.jpg'], array_map(static fn (SocialMedia $media): ?string => $media->getFile() instanceof UploadedFile ? $media->getFile()->getClientOriginalName() : null, \array_slice($medias, 1)));
        $this->assertSame(1, $this->flushes);
    }

    // One file of another type refuses the whole drop, saying which; a forged request, a post gone out or nothing sent change nothing
    public function testADropWithAFileOfAnotherTypeAddsNothing(): void
    {
        $refused = $this->upload([$this->uploaded('a.jpg', $this->jpeg()), $this->uploaded('notes.txt', 'Hello')]);

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $refused->getStatusCode());
        $this->assertSame('label.social_calendar_upload_refused', $refused->getContent());
        $this->assertSame(Response::HTTP_FORBIDDEN, $this->upload([$this->uploaded('a.jpg', $this->jpeg())], 'forged')->getStatusCode());
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->upload([])->getStatusCode());
        $this->target()->markPublished('ext-1');
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->upload([$this->uploaded('a.jpg', $this->jpeg())])->getStatusCode());
        $this->assertCount(0, $this->post->getMedias());
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

    // The panel of a series' post names the coming posts around it, none past either end nor for a post of no series
    public function testThePanelStepsThroughTheSeries(): void
    {
        new \ReflectionProperty(SocialPost::class, 'id')->setValue($this->post, 42);
        $request = Request::create('/', 'GET', ['id' => '42', 'view' => 'series']);

        $this->createController($request)->panel($request);
        $this->assertSame(['previous' => null, 'next' => null], $this->rendered['siblings']);

        $this->seriesPost('Other');
        $this->seriesIds = [41, 42, 43];
        $this->createController($request)->panel($request);
        $this->assertSame(41, $this->rendered['siblings']['previous']['id'] ?? null);
        $this->assertSame(43, $this->rendered['siblings']['next']['id'] ?? null);

        $this->seriesIds = [42, 43];
        $this->createController($request)->panel($request);
        $this->assertNull($this->rendered['siblings']['previous']);
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

    /** @param list<string> $networks */
    private function networks(array $networks, string $token = 'valid'): Response
    {
        $request = Request::create('/', 'POST', ['post' => '42', 'networks' => $networks]);
        $request->headers->set('X-CSRF-Token', $token);

        return $this->createController($request)->networks($request);
    }

    // A connected network ticked gets its text written, one unticked drops its own, the panel answered as it now reads with the connected networks to tick
    public function testTheNetworksTickedInThePanelAreSaved(): void
    {
        $this->assertSame(Response::HTTP_OK, $this->networks(['linkedin'])->getStatusCode());

        $this->assertSame(['linkedin'], $this->added);
        $this->assertSame(['linkedin'], $this->post->getNetworks());
        $this->assertSame(1, $this->flushes);
        $this->assertSame(['bluesky', 'linkedin'], $this->rendered['offered_networks']);
        $this->assertSame(['linkedin'], $this->rendered['networks']);
    }

    // Networks ticked whose medias would be refused are saved all the same, the panel warning of it as the post's screen does
    public function testNetworksTickedWhoseMediasDoNotSuitAreWarned(): void
    {
        $this->mediaChecker = $this->createStub(SocialMediaChecker::class);
        $this->mediaChecker->method('check')->willReturn(['instagram' => [new TranslatableMessage('label.social_media_required')]]);

        $this->assertSame(Response::HTTP_OK, $this->networks(['linkedin'])->getStatusCode());
        $this->assertSame(['linkedin'], $this->post->getNetworks());
        $this->assertSame('Instagram : label.social_media_required', $this->rendered['media_problems']);
    }

    // A network not connected is not offered: sent anyway it is not added, and one the post already goes to stays
    public function testANetworkNotConnectedIsLeftAsItWas(): void
    {
        new SocialPostTarget($this->post, 'facebook', 'Facebook');

        $this->networks(['bluesky', 'facebook', 'instagram']);
        $this->assertSame([], $this->added);
        $this->assertEqualsCanonicalizing(['bluesky', 'facebook'], $this->post->getNetworks());

        $this->networks([]);
        $this->assertSame(['facebook'], $this->post->getNetworks());
    }

    // Ticked on the series' page, the networks are saved and nothing is rendered, the page reloading itself
    public function testTheNetworksTickedOnTheSeriesPageAnswerNoPanel(): void
    {
        $request = Request::create('/', 'POST', ['post' => '42', 'networks' => ['linkedin'], 'bare' => '1']);
        $request->headers->set('X-CSRF-Token', 'valid');

        $this->assertSame(Response::HTTP_NO_CONTENT, $this->createController($request)->networks($request)->getStatusCode());
        $this->assertSame(['linkedin'], $this->post->getNetworks());
        $this->assertSame(1, $this->flushes);
        $this->assertSame([], $this->rendered);
    }

    // A post gone out anywhere keeps its networks, and a forged request changes nothing
    public function testAPostGoneOutAndAForgedRequestKeepTheirNetworks(): void
    {
        $this->assertSame(Response::HTTP_FORBIDDEN, $this->networks(['linkedin'], 'forged')->getStatusCode());
        $this->target()->markPublished('ext-1');

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->networks(['linkedin'])->getStatusCode());
        $this->assertSame(['bluesky'], $this->post->getNetworks());
        $this->assertSame(0, $this->flushes);
    }

    // The copy is saved, answered by the calendar's address on its day; a forged request copies nothing
    public function testAPostIsDuplicated(): void
    {
        $request = Request::create('/', 'POST', ['post' => '42']);
        $request->headers->set('X-CSRF-Token', 'forged');
        $this->assertSame(Response::HTTP_FORBIDDEN, $this->createController($request)->duplicate($request)->getStatusCode());

        $request->headers->set('X-CSRF-Token', 'valid');
        $this->assertSame(Response::HTTP_OK, $this->createController($request)->duplicate($request)->getStatusCode());
        $this->assertCount(1, $this->persisted);
        $this->assertInstanceOf(SocialPost::class, $this->persisted[0]);
        $this->assertSame(1, $this->flushes);
    }

    // Asked from the series' page, the copy's panel opens back there - the calendar answered for a post of no series
    public function testADuplicateAskedFromTheSeriesComesBackToIt(): void
    {
        $duplicate = function (): string {
            $request = Request::create('/?view=series', 'POST', ['post' => '42']);
            $request->headers->set('X-CSRF-Token', 'valid');

            return (string) $this->createController($request)->duplicate($request)->getContent();
        };

        $this->assertSame('/' . SocialCalendarController::ROUTE, $duplicate());
        $this->seriesPost('Other');
        $this->assertSame('/' . SocialCalendarController::SERIES_ROUTE, $duplicate());
    }

    private function retry(string $token = 'valid'): Response
    {
        $request = Request::create('/', 'POST', ['post' => '42', 'network' => 'bluesky']);
        $request->headers->set('X-CSRF-Token', $token);

        return $this->createController($request)->retry($request);
    }

    // The network named alone is sent again, the panel answered as it now reads - not without the panel's token
    public function testARefusedNetworkIsSentAgainAlone(): void
    {
        $this->assertSame(Response::HTTP_FORBIDDEN, $this->retry('forged')->getStatusCode());
        $this->assertSame([], $this->retried);

        $this->assertSame(Response::HTTP_OK, $this->retry()->getStatusCode());
        $this->assertSame(['bluesky'], $this->retried);
        $this->assertSame($this->post->getTargets(), $this->rendered['targets']);
    }

    // A post another "Publish" is sending is refused with the reason
    public function testARetryWhileThePostIsSendingIsRefused(): void
    {
        $this->retryReport = null;
        $response = $this->retry();

        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $this->assertSame('flash.social_post_publishing', $response->getContent());
    }

    // A network not among the post's, or no longer refused there, sends nothing: refused with the reason rather than answered as if sent
    public function testARetrySendingNothingIsRefused(): void
    {
        $this->retryReport = [];
        $response = $this->retry();

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertSame('label.social_calendar_retry_nothing', $response->getContent());
    }

    private function series(?string $id): Response
    {
        $request = Request::create('/', 'GET', null === $id ? [] : ['id' => $id]);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $this->createController($request)->series($request);
    }

    // The series' coming posts, each with what its card needs, counted by their approval
    public function testTheSeriesShowsItsComingPostsCounted(): void
    {
        $other = $this->seriesPost('Other');
        $other->approve();

        $this->series('3');

        $this->assertCount(2, $this->rendered['posts']);
        $this->assertSame(1, $this->rendered['approved']);
        $this->assertSame(1, $this->rendered['to_approve']);
        $this->assertSame(['bluesky'], $this->rendered['posts'][0]['ticked']);
        $this->assertNull($this->rendered['posts'][0]['video_url']);
        $this->assertTrue($this->rendered['posts'][0]['editable']);
        $this->assertSame(['bluesky', 'linkedin'], $this->rendered['offered_networks']);
    }

    // A series that does not exist is not found
    public function testAnUnknownSeriesIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->series('99');
    }

    // An id of 0 is not found either, rather than read as the menu's entry
    public function testASeriesIdOfZeroIsNotFound(): void
    {
        $this->seriesPost('Other');
        $this->seriesRows = [['series' => $this->post->getSeries(), 'lastPlannedAt' => $this->plannedAt->modify('+1 day')]];

        $this->expectException(NotFoundHttpException::class);

        $this->series('0');
    }

    // The menu's entry names no series: the first one still running opens, an ended one left out, its last moment read from the list
    public function testTheMenuOpensTheFirstSeriesStillRunning(): void
    {
        $this->seriesPost('Other');
        $ended = ['series' => new SocialSeries('Ended', 3, 'daily', 1, [], 'text', 'Text', [], ['bluesky']), 'lastPlannedAt' => new \DateTimeImmutable('-1 day')];
        $running = ['series' => $this->post->getSeries(), 'lastPlannedAt' => $this->plannedAt->modify('+1 day')];
        $this->seriesRows = [$ended, $running];

        $this->series(null);

        $this->assertSame($this->post->getSeries(), $this->rendered['series']);
        $this->assertSame([$running], $this->rendered['others']);
        $this->assertSame($running['lastPlannedAt'], $this->rendered['last']);
    }

    // None running: the calendar opens, saying so
    public function testWithNoSeriesRunningTheMenuOpensTheCalendar(): void
    {
        $response = $this->series(null);

        $this->assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        $this->assertSame('/' . SocialCalendarController::ROUTE, $response->headers->get('Location'));
    }

    // A series ended, opened by its address, is listed at its place beside the running ones, another ended one left out
    public function testASeriesEndedIsListedBesideTheRunningOnes(): void
    {
        $this->seriesPost('Other');
        $running = new SocialSeries('Running', 3, 'daily', 1, [], 'text', 'Text', [], ['bluesky']);
        $this->seriesRows = [
            ['series' => new SocialSeries('Ended', 3, 'daily', 1, [], 'text', 'Text', [], ['bluesky']), 'lastPlannedAt' => new \DateTimeImmutable('-2 days')],
            ['series' => $this->post->getSeries(), 'lastPlannedAt' => new \DateTimeImmutable('-1 day')],
            ['series' => $running, 'lastPlannedAt' => new \DateTimeImmutable('+1 day')],
        ];

        $this->series('3');

        $this->assertSame([$this->post->getSeries(), $running], array_column($this->rendered['others'], 'series'));
    }

    // The drafts checked on the series' page are approved, the ones left unchecked staying drafts
    public function testOnlyTheDraftsPickedAreApproved(): void
    {
        $other = $this->seriesPost('Other');
        $third = $this->seriesPost('Third');
        foreach ([[$this->post, 42], [$other, 43], [$third, 44]] as [$post, $id]) {
            new \ReflectionProperty(SocialPost::class, 'id')->setValue($post, $id);
        }

        $this->assertSame(Response::HTTP_NO_CONTENT, $this->movePicked(['42', '44'])->getStatusCode());
        $this->assertSame('approved', $this->post->getState());
        $this->assertSame('draft', $other->getState());
        $this->assertSame('approved', $third->getState());
    }

    /** @param list<string> $ids */
    private function movePicked(array $ids): Response
    {
        $request = Request::create('/', 'POST', ['post' => '42', 'to' => 'approve-picked', 'posts' => $ids]);
        $request->headers->set('X-CSRF-Token', 'valid');

        return $this->createController($request)->move($request);
    }

    // An empty selection is refused rather than read as the whole series
    public function testAnEmptySelectionApprovesNothing(): void
    {
        $other = $this->seriesPost('Other');

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->movePicked([])->getStatusCode());
        $this->assertSame('draft', $other->getState());
    }

    // A post checked that is no longer a draft is said rather than dropped, the others approved all the same
    public function testAPickedPostNoLongerADraftIsSaid(): void
    {
        $other = $this->seriesPost('Other');
        foreach ([[$this->post, 42], [$other, 43]] as [$post, $id]) {
            new \ReflectionProperty(SocialPost::class, 'id')->setValue($post, $id);
        }
        $other->approve();

        $response = $this->movePicked(['42', '43']);

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $this->assertSame('label.social_series_review_skipped', $response->getContent());
        $this->assertSame('approved', $this->post->getState());
    }
}
