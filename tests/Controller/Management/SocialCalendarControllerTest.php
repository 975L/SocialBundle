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
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Enum\SocialPostStatus;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Service\SocialMediaChecker;
use c975L\SocialBundle\Service\SocialPlanner;
use c975L\SocialBundle\Service\SocialPublisher;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

// What a card dropped on the calendar, or the panel's buttons, do to its post: the move is the one thing this screen writes
class SocialCalendarControllerTest extends TestCase
{
    // The media checker the next controller built is given, a stub answering no problem otherwise
    private ?SocialMediaChecker $mediaChecker = null;

    private SocialPost $post;

    private \DateTimeImmutable $plannedAt;

    private int $flushes = 0;

    protected function setUp(): void
    {
        $this->plannedAt = new \DateTimeImmutable('+1 day 09:30');
        $this->post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, $this->plannedAt);
        new SocialPostTarget($this->post, 'bluesky', 'Text');
    }

    private function controller(): SocialCalendarController
    {
        return new SocialCalendarController(
            $this->createStub(ConfigServiceInterface::class),
            new SocialPlanner(),
            $this->createStub(SocialPublisher::class),
            $this->createStub(SocialPostRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AdminUrlGeneratorInterface::class),
            $this->mediaChecker ?? $this->createStub(SocialMediaChecker::class),
            $this->createStub(TranslatorInterface::class),
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

    // The card's colour: a draft, an approved post, one refused somewhere, one gone out on every network
    public function testTheStateOfAPostSaysWhereItStands(): void
    {
        $state = fn (): string => new \ReflectionMethod(SocialCalendarController::class, 'state')->invoke($this->controller(), $this->post);

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

        $this->assertSame('draft', new \ReflectionMethod(SocialCalendarController::class, 'state')->invoke($this->controller(), $post));
    }

    // Same seam as the OAuth controllers' tests: AbstractController resolves security and the csrf check through its container
    private function createController(Request $request): SocialCalendarController
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key) => 'site-role-editor' === $key ? 'ROLE_EDITOR' : null);

        $postRepository = $this->createStub(SocialPostRepository::class);
        $postRepository->method('find')->willReturnCallback(fn (mixed $id): ?SocialPost => 42 === $id ? $this->post : null);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        $controller = new SocialCalendarController(
            $configService,
            new SocialPlanner(),
            $this->createStub(SocialPublisher::class),
            $postRepository,
            $entityManager,
            $this->createStub(AdminUrlGeneratorInterface::class),
            $this->mediaChecker ?? $this->createStub(SocialMediaChecker::class),
            $this->createStub(TranslatorInterface::class),
        );

        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn(true);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $csrfToken): bool => 'valid' === $csrfToken->getValue());
        $services = ['request_stack' => new RequestStack([$request]), 'security.authorization_checker' => $authorizationChecker, 'security.csrf.token_manager' => $csrf];
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

    // The move plans a post that goes out under the site's name: no token, nothing changes
    public function testAMoveWithoutTheTokenIsRefused(): void
    {
        $this->assertSame(Response::HTTP_FORBIDDEN, $this->move('approve', 'forged')->getStatusCode());
        $this->assertSame(SocialPostStatus::Draft, $this->target()->getStatus());
    }
}
