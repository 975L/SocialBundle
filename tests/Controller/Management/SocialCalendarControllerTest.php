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
use c975L\SocialBundle\Repository\SocialScheduleRepository;
use c975L\SocialBundle\Service\SocialPlanner;
use c975L\SocialBundle\Service\SocialPublisher;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

// What a card dropped on the calendar does to its post: the move is the one thing this screen writes
class SocialCalendarControllerTest extends TestCase
{
    private SocialPost $post;

    private int $flushes = 0;

    protected function setUp(): void
    {
        $this->post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null);
        new SocialPostTarget($this->post, 'bluesky', 'Text');
    }

    private function controller(): SocialCalendarController
    {
        $postRepository = $this->createStub(SocialPostRepository::class);
        $scheduleRepository = $this->createStub(SocialScheduleRepository::class);

        return new SocialCalendarController(
            $this->createStub(ConfigServiceInterface::class),
            new SocialPlanner($postRepository, $scheduleRepository),
            $this->createStub(SocialPublisher::class),
            $postRepository,
            $scheduleRepository,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AdminUrlGeneratorInterface::class),
        );
    }

    // A card is taller than a quarter of an hour: items whose heights overlap go side by side, the next one free again taking the first lane back
    public function testOverlappingItemsOfTheWeekGoSideBySide(): void
    {
        $item = static fn (string $kind, string $at): array => ['kind' => $kind, 'at' => new \DateTimeImmutable('2026-10-04 ' . $at)];
        $rows = new \ReflectionMethod(SocialCalendarController::class, 'rows')->invoke($this->controller(), [
            $item('slot', '19:00'), $item('planned', '19:15'), $item('planned', '19:30'), $item('planned', '21:00'),
        ]);

        // From 06:00, 19:00 is the 52nd quarter of an hour
        $this->assertSame([[52, 0], [53, 1], [54, 2], [60, 0]], array_map(static fn (array $group): array => [$group['row'], $group['lane']], $rows));
        $this->assertSame([3], array_values(array_unique(array_column($rows, 'lanes'))));
    }

    // Same seam as the OAuth controllers' tests: AbstractController resolves security, the csrf check and the flash bag through its container
    private function createController(Request $request, ?SocialPublisher $publisher = null): SocialCalendarController
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key) => 'site-role-editor' === $key ? 'ROLE_EDITOR' : null);

        $postRepository = $this->createStub(SocialPostRepository::class);
        $postRepository->method('find')->willReturnCallback(fn (mixed $id): ?SocialPost => 42 === $id ? $this->post : null);
        $postRepository->method('findDrafts')->willReturnCallback(fn (): array => [$this->post]);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        $scheduleRepository = $this->createStub(SocialScheduleRepository::class);
        $adminUrlGenerator = $this->createStub(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->method('unsetAll')->willReturnSelf();
        $adminUrlGenerator->method('setController')->willReturnSelf();
        $adminUrlGenerator->method('setAction')->willReturnSelf();
        $adminUrlGenerator->method('setEntityId')->willReturnSelf();
        $adminUrlGenerator->method('generateUrl')->willReturn('/edit');
        $controller = new SocialCalendarController(
            $configService,
            new SocialPlanner($postRepository, $scheduleRepository),
            $publisher ?? $this->createStub(SocialPublisher::class),
            $postRepository,
            $scheduleRepository,
            $entityManager,
            $adminUrlGenerator,
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

    private function targetStatus(): SocialPostStatus
    {
        $target = $this->post->getTargets()->first();

        return $target instanceof SocialPostTarget ? $target->getStatus() : SocialPostStatus::Failed;
    }

    // A draft dropped on a slot is approved and planned there, the moment brought to the server's own time zone
    public function testADraftDroppedOnASlotIsApprovedAndPlannedThere(): void
    {
        $at = new \DateTimeImmutable('+2 days 19:00');

        $this->assertSame(Response::HTTP_NO_CONTENT, $this->move($at->format('c'))->getStatusCode());
        $this->assertSame(SocialPostStatus::Approved, $this->targetStatus());
        $this->assertEquals($at, $this->post->getPlannedAt());
        $this->assertSame(1, $this->flushes);
    }

    // The panel's field, typed in the server's time zone, is read there and brought to the nearest quarter of an hour; a drop on a slot stays exact
    public function testAMomentTypedInThePanelIsReadInTheServerTimeZoneToTheQuarter(): void
    {
        $day = new \DateTimeImmutable('+2 days');

        $this->move($day->format('Y-m-d') . 'T10:08');
        $this->assertEquals($day->setTime(10, 15), $this->post->getPlannedAt());

        $this->move($day->setTime(10, 7)->format('c'));
        $this->assertEquals($day->setTime(10, 7), $this->post->getPlannedAt());
    }

    // Dropped on the queue, a planned post goes back to the next slot free
    public function testAPostDroppedOnTheQueueIsUnplanned(): void
    {
        $this->post->setPlannedAt(new \DateTimeImmutable('+2 days'));

        $this->move('queue');

        $this->assertSame(SocialPostStatus::Approved, $this->targetStatus());
        $this->assertNull($this->post->getPlannedAt());
    }

    // Dropped on the drafts, a post leaves the queue and waits for a reading again
    public function testAPostDroppedOnTheDraftsLeavesTheQueue(): void
    {
        $this->post->approve();
        $this->post->setPlannedAt(new \DateTimeImmutable('+2 days'));

        $this->move('drafts');

        $this->assertSame(SocialPostStatus::Draft, $this->targetStatus());
        $this->assertNull($this->post->getPlannedAt());
    }

    // A slot already gone would send nothing: the post would only wait for the next one, somewhere it was not dropped
    public function testASlotGoneOrAnUnknownPlaceIsRefused(): void
    {
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->move(new \DateTimeImmutable('-1 hour')->format('c'))->getStatusCode());
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->move('nowhere')->getStatusCode());
        $this->assertSame(SocialPostStatus::Draft, $this->targetStatus());
        $this->assertSame(0, $this->flushes);
    }

    // The move plans a post that goes out under the site's name: no token, nothing changes
    public function testAMoveWithoutTheTokenIsRefused(): void
    {
        $this->assertSame(Response::HTTP_FORBIDDEN, $this->move('queue', 'forged')->getStatusCode());
        $this->assertSame(SocialPostStatus::Draft, $this->targetStatus());
    }

    // A slot already holding a draft, from a second click or another tab, opens it rather than preparing a second post for the same moment
    public function testASlotAlreadyPreparedIsNotPreparedTwice(): void
    {
        $at = new \DateTimeImmutable('+1 day')->setTime(9, 0);
        $this->post->setPlannedAt($at);
        $publisher = $this->createMock(SocialPublisher::class);
        $publisher->expects($this->never())->method('prepareForSlot');
        $request = Request::create('/', 'POST', ['slot' => '3', 'at' => $at->format('c'), '_token' => 'valid']);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $response = $this->createController($request, $publisher)->prepare($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/edit', $response->getTargetUrl());
        $session = $request->getSession();
        \assert($session instanceof Session);
        $this->assertArrayHasKey('warning', $session->getFlashBag()->all());
    }
}
