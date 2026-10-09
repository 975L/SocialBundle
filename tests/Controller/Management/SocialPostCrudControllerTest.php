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
use c975L\SocialBundle\Controller\Management\SocialPostCrudController;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Enum\SocialPostStatus;
use c975L\SocialBundle\Repository\SocialSeriesRepository;
use c975L\SocialBundle\Service\SocialMediaChecker;
use c975L\SocialBundle\Service\SocialMediaPicker;
use c975L\SocialBundle\Service\SocialPlanner;
use c975L\SocialBundle\Service\SocialPublisher;
use c975L\SocialBundle\Service\SocialSeriesGenerator;
use c975L\UiBundle\Contract\BrowsableSocialContentSourceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Context\CrudContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class SocialPostCrudControllerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $rendered = [];

    private string $view = '';

    private function controller(?EntityManagerInterface $entityManager = null, ?SocialPublisher $socialPublisher = null, ?SocialSeriesGenerator $seriesGenerator = null, ?SocialSeriesRepository $seriesRepository = null): SocialPostCrudController
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key) => 'site-role-editor' === $key ? 'ROLE_EDITOR' : null);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $adminUrlGenerator = $this->createStub(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->method('unsetAll')->willReturnSelf();
        $adminUrlGenerator->method('setController')->willReturnSelf();
        $action = '';
        $adminUrlGenerator->method('setAction')->willReturnCallback(static function (string $name) use (&$action, $adminUrlGenerator): AdminUrlGeneratorInterface {
            $action = $name;

            return $adminUrlGenerator;
        });
        $adminUrlGenerator->method('setEntityId')->willReturnSelf();
        $adminUrlGenerator->method('generateUrl')->willReturnCallback(static function () use (&$action): string {
            return '/admin/' . $action;
        });

        return new SocialPostCrudController(
            $configService,
            $socialPublisher ?? $this->createStub(SocialPublisher::class),
            $adminUrlGenerator,
            $this->createStub(CsrfTokenManagerInterface::class),
            $translator,
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $this->createStub(SocialPlanner::class),
            $this->createStub(SocialMediaChecker::class),
            $seriesGenerator ?? $this->createStub(SocialSeriesGenerator::class),
            $seriesRepository ?? $this->createStub(SocialSeriesRepository::class),
            $this->createStub(SocialMediaPicker::class),
        );
    }

    private function configureActions(): Actions
    {
        // A real EasyAdmin runtime pre-populates the default actions before calling configureActions()
        return $this->controller()->configureActions(
            Actions::new()
                ->add(Crud::PAGE_INDEX, Action::EDIT)
                ->add(Crud::PAGE_INDEX, Action::DELETE)
        );
    }

    public function testEveryActionDemandsSiteRoleEditor(): void
    {
        $permissions = $this->configureActions()->getAsDto(null)->getActionPermissions();

        foreach ([Action::INDEX, Action::EDIT, Action::DELETE, 'publishPost', 'approvePost', 'unapprovePost', 'prepareUrlPost', 'generateSeries', 'approveSelection', 'pickMedia'] as $action) {
            $this->assertSame('ROLE_EDITOR', $permissions[$action], $action);
        }
    }

    // On the post's page a link would send the text as saved, not as just corrected: "Publish" is offered on the list only
    public function testPublishIsOfferedOnTheListOnly(): void
    {
        $actions = $this->configureActions();

        $this->assertNotNull($actions->getAsDto(Crud::PAGE_INDEX)->getAction(Crud::PAGE_INDEX, 'publishPost'));
        $this->assertNull($actions->getAsDto(Crud::PAGE_EDIT)->getAction(Crud::PAGE_EDIT, 'publishPost'));
    }

    // The libraries' medias are added to a saved post, from its page
    public function testPickingMediasIsOfferedOnThePostsPage(): void
    {
        $actions = $this->configureActions();

        $this->assertNotNull($actions->getAsDto(Crud::PAGE_EDIT)->getAction(Crud::PAGE_EDIT, 'pickMedia'));
        $this->assertNull($actions->getAsDto(Crud::PAGE_INDEX)->getAction(Crud::PAGE_INDEX, 'pickMedia'));
    }

    // A page's address prepares a draft from the list, the hourly run and its batch being gone
    public function testPostsArePreparedFromAnAddress(): void
    {
        $actions = $this->configureActions();

        $this->assertNotNull($actions->getAsDto(Crud::PAGE_INDEX)->getAction(Crud::PAGE_INDEX, 'prepareUrlPost'));
        $this->assertNull($actions->getAsDto(Crud::PAGE_INDEX)->getAction(Crud::PAGE_INDEX, 'prepareNextPost'));
        $this->assertNull($actions->getAsDto(Crud::PAGE_INDEX)->getAction(Crud::PAGE_INDEX, 'prepareDrafts'));
    }

    // Approving is a gesture of the list, next to "Publish", the post's page saving the planned moment with its texts
    public function testApprovingIsOfferedOnTheList(): void
    {
        $actions = $this->configureActions()->getAsDto(Crud::PAGE_INDEX);

        $this->assertNotNull($actions->getAction(Crud::PAGE_INDEX, 'approvePost'));
        $this->assertNotNull($actions->getAction(Crud::PAGE_INDEX, 'unapprovePost'));
    }

    // A selection sent without EasyAdmin's batch token, from another site, approves nothing
    public function testApprovingASelectionDemandsItsToken(): void
    {
        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn(true);
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->expects($this->once())->method('isTokenValid')
            ->with(new CsrfToken('ea-batch-action-approveSelection-' . SocialPost::class, 'forged'))
            ->willReturn(false);
        $services = ['security.authorization_checker' => $authorizationChecker, 'security.csrf.token_manager' => $csrfTokenManager];
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => isset($services[$id]));
        $container->method('get')->willReturnCallback(static fn (string $id) => $services[$id] ?? null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('find');
        $entityManager->expects($this->never())->method('flush');
        $controller = $this->controller($entityManager);
        $controller->setContainer($container);

        $controller->approveSelection(new BatchActionDto('approveSelection', [1, 2], SocialPost::class, 'forged'));
    }

    // Same seam as the calendar's tests: AbstractController resolves security, the csrf check, the flashes, Twig and the router through its container, the post coming from EasyAdmin's context
    private function call(SocialPostCrudController $controller, string $action, SocialPost $post, Request $request): Response
    {
        $request->setSession(new Session(new MockArraySessionStorage()));
        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn(true);
        $csrf = $this->createStub(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $csrfToken): bool => 'social_post_publish' === $csrfToken->getId() && 'valid' === $csrfToken->getValue());
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $view, array $parameters): string {
            $this->view = $view;
            $this->rendered = $parameters;

            return '';
        });
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route, array $parameters): string => '/' . $route . '?' . http_build_query($parameters));
        $services = [
            'request_stack' => new RequestStack([$request]),
            'security.authorization_checker' => $authorizationChecker,
            'security.csrf.token_manager' => $csrf,
            'twig' => $twig,
            'router' => $router,
        ];
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => isset($services[$id]));
        $container->method('get')->willReturnCallback(static fn (string $id) => $services[$id] ?? null);
        $controller->setContainer($container);

        $context = AdminContext::forTesting(crudContext: CrudContext::forTesting(entityDto: new EntityDto(SocialPost::class, new ClassMetadata(SocialPost::class), null, $post)));

        return $controller->{$action}($context, $request);
    }

    private function post(): SocialPost
    {
        return new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable('+1 day 09:00'));
    }

    private function series(): SocialSeries
    {
        return new SocialSeries('Photo du soir', 7, 'days', 1, [], 'text', 'Photo du soir', [], ['bluesky']);
    }

    // The refusal email's page lists the networks that refused the post only, sending nothing yet
    public function testRetryingShowsTheRefusedTargets(): void
    {
        $post = $this->post();
        $failed = new SocialPostTarget($post, 'bluesky', 'Text');
        $failed->markFailed('Refused');
        new SocialPostTarget($post, 'linkedin', 'Text')->markPublished('ext-1');
        $publisher = $this->createMock(SocialPublisher::class);
        $publisher->expects($this->never())->method('retryFailed');

        $response = $this->call($this->controller(socialPublisher: $publisher), 'retryPost', $post, Request::create('/'));

        $this->assertNotInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('@c975LSocial/management/social_post_retry.html.twig', $this->view);
        $this->assertSame([$failed], array_values($this->rendered['failed']->toArray()));
        $this->assertSame('/admin/' . Action::EDIT, $this->rendered['edit_url']);
    }

    // Confirmed, only the refused targets are sent again - never the whole post - and the post is opened
    public function testRetryingSendsTheRefusedTargetsAgain(): void
    {
        $post = $this->post();
        $publisher = $this->createMock(SocialPublisher::class);
        $publisher->expects($this->once())->method('retryFailed')->with($post)->willReturn(['bluesky' => ['status' => SocialPostStatus::Published->value, 'message' => '']]);
        $publisher->expects($this->never())->method('publish');

        $response = $this->call($this->controller(socialPublisher: $publisher), 'retryPost', $post, Request::create('/', 'POST', ['retry' => '1', 'token' => 'valid']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/admin/' . Action::EDIT, $response->getTargetUrl());
    }

    // A confirmation sent without its token, from another site, sends nothing again
    public function testRetryingDemandsItsToken(): void
    {
        $publisher = $this->createMock(SocialPublisher::class);
        $publisher->expects($this->never())->method('retryFailed');

        $this->call($this->controller(socialPublisher: $publisher), 'retryPost', $this->post(), Request::create('/', 'POST', ['retry' => '1', 'token' => 'forged']));

        $this->assertSame('@c975LSocial/management/social_post_retry.html.twig', $this->view);
    }

    // A post outside any series has nothing to prolong: it is opened again
    public function testProlongingAPostOutsideASeriesOpensIt(): void
    {
        $generator = $this->createMock(SocialSeriesGenerator::class);
        $generator->expects($this->never())->method('prolong');

        $response = $this->call($this->controller(seriesGenerator: $generator), 'prolongSeries', $this->post(), Request::create('/', 'POST', ['prolong' => '1', 'token' => 'valid']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/admin/' . Action::EDIT, $response->getTargetUrl());
    }

    // Prolonging first asks to confirm, showing the series and its last planned moment
    public function testProlongingAsksToConfirm(): void
    {
        $series = $this->series();
        $post = $this->post()->setSeries($series);
        $last = new \DateTimeImmutable('+7 days 21:00');
        $repository = $this->createStub(SocialSeriesRepository::class);
        $repository->method('findLastPlannedAt')->willReturn($last);
        $generator = $this->createMock(SocialSeriesGenerator::class);
        $generator->expects($this->never())->method('prolong');

        $this->call($this->controller(seriesGenerator: $generator, seriesRepository: $repository), 'prolongSeries', $post, Request::create('/'));

        $this->assertSame('@c975LSocial/management/social_series_prolong.html.twig', $this->view);
        $this->assertSame($series, $this->rendered['series']);
        $this->assertSame($last, $this->rendered['last']);
    }

    // Confirmed, the series is prolonged and the calendar opens on its first new draft
    public function testProlongingShowsTheNewDraftsOnTheCalendar(): void
    {
        $series = $this->series();
        $post = $this->post()->setSeries($series);
        $draft = new SocialPost(SocialPost::SOURCE_MANUAL, 'next', 'Next', '', null, new \DateTimeImmutable('2026-11-02 21:00'));
        $generator = $this->createMock(SocialSeriesGenerator::class);
        $generator->expects($this->once())->method('prolong')->with($series)->willReturn([$draft]);

        $response = $this->call($this->controller(seriesGenerator: $generator), 'prolongSeries', $post, Request::create('/', 'POST', ['prolong' => '1', 'token' => 'valid']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/' . SocialCalendarController::ROUTE . '?date=2026-11-02', $response->getTargetUrl());
    }

    // A series giving no new draft opens the post again
    public function testProlongingToNothingOpensThePostAgain(): void
    {
        $generator = $this->createStub(SocialSeriesGenerator::class);
        $generator->method('prolong')->willReturn([]);

        $response = $this->call($this->controller(seriesGenerator: $generator), 'prolongSeries', $this->post()->setSeries($this->series()), Request::create('/', 'POST', ['prolong' => '1', 'token' => 'valid']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/admin/' . Action::EDIT, $response->getTargetUrl());
    }

    // A post whose source cannot be browsed keeps its content: it is opened again
    public function testChangingTheContentOfAnUnbrowsableSourceOpensThePost(): void
    {
        $publisher = $this->createMock(SocialPublisher::class);
        $publisher->method('browsableSourceOf')->willReturn(null);
        $publisher->expects($this->never())->method('changeContent');

        $response = $this->call($this->controller(socialPublisher: $publisher), 'changeContent', $this->post(), Request::create('/', 'POST', ['choose' => '1', 'sourceId' => '7', 'token' => 'valid']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/admin/' . Action::EDIT, $response->getTargetUrl());
    }

    // The contents offered are those of the post's own group by default
    public function testChangingTheContentListsThoseOfThePostsGroup(): void
    {
        $post = $this->post();
        $source = $this->createStub(BrowsableSocialContentSourceInterface::class);
        $source->method('getContentScope')->willReturnCallback(static fn (string $sourceId): ?string => '42' === $sourceId ? 'album-3' : null);
        $publisher = $this->createMock(SocialPublisher::class);
        $publisher->method('browsableSourceOf')->willReturn($source);
        $publisher->expects($this->once())->method('contentChoices')->with($post, 'album-3', SocialMediaPicker::LIMIT)->willReturn([]);

        $this->call($this->controller(socialPublisher: $publisher), 'changeContent', $post, Request::create('/'));

        $this->assertSame('@c975LSocial/management/social_content_change.html.twig', $this->view);
        $this->assertSame('album-3', $this->rendered['scope']);
        $this->assertSame([], $this->rendered['scopes']);
    }

    // Drawing again ties the post to another content of its group, saved, and opens the post
    public function testRedrawingTheContentSavesIt(): void
    {
        $post = $this->post();
        $publisher = $this->createMock(SocialPublisher::class);
        $publisher->method('browsableSourceOf')->willReturn($this->createStub(BrowsableSocialContentSourceInterface::class));
        $publisher->expects($this->once())->method('redraw')->with($post)->willReturn(true);
        $publisher->expects($this->never())->method('changeContent');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $response = $this->call($this->controller($entityManager, $publisher), 'changeContent', $post, Request::create('/', 'POST', ['redraw' => '1', 'token' => 'valid']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/admin/' . Action::EDIT, $response->getTargetUrl());
    }

    // The content chosen is the one the post is tied to
    public function testChoosingAContentTiesThePostToIt(): void
    {
        $post = $this->post();
        $publisher = $this->createMock(SocialPublisher::class);
        $publisher->method('browsableSourceOf')->willReturn($this->createStub(BrowsableSocialContentSourceInterface::class));
        $publisher->expects($this->once())->method('changeContent')->with($post, '7')->willReturn(true);
        $publisher->expects($this->never())->method('redraw');

        $response = $this->call($this->controller(socialPublisher: $publisher), 'changeContent', $post, Request::create('/', 'POST', ['choose' => '1', 'sourceId' => '7', 'token' => 'valid']));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/admin/' . Action::EDIT, $response->getTargetUrl());
    }

    // A change sent without its token, from another site, changes nothing
    public function testChangingTheContentDemandsItsToken(): void
    {
        $publisher = $this->createMock(SocialPublisher::class);
        $publisher->method('browsableSourceOf')->willReturn($this->createStub(BrowsableSocialContentSourceInterface::class));
        $publisher->expects($this->never())->method('changeContent');
        $publisher->expects($this->never())->method('redraw');

        $this->call($this->controller(socialPublisher: $publisher), 'changeContent', $this->post(), Request::create('/', 'POST', ['choose' => '1', 'sourceId' => '7', 'token' => 'forged']));

        $this->assertSame('@c975LSocial/management/social_content_change.html.twig', $this->view);
    }
}
