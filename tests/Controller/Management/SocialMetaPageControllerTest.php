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
use c975L\SocialBundle\Controller\Management\SocialMetaPageController;
use c975L\SocialBundle\Service\ConfigValueWriter;
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
use Symfony\Contracts\Translation\TranslatableInterface;

class SocialMetaPageControllerTest extends TestCase
{
    private const array PAGE = ['pageId' => '55', 'name' => 'Papa Câlin', 'pageToken' => 'page-token', 'instagramId' => '77', 'instagram' => 'papacalin'];

    private const array OTHER_PAGE = ['pageId' => '11', 'name' => 'Other', 'pageToken' => 'other-token', 'instagramId' => null, 'instagram' => null];

    /** @var list<array<string, ?string>> */
    private array $written = [];

    // A site not yet through load-all: the names' slugs not declared
    private bool $namesUndeclared = false;

    // The Pages held as the callback holds them, posted to when a page id is given
    private function createRequest(array $pages = [self::OTHER_PAGE, self::PAGE], ?string $pageId = null, string $token = 'valid', int $expires = 600): Request
    {
        $request = null === $pageId ? new Request() : new Request([], ['page' => $pageId, 'token' => $token]);
        if (null !== $pageId) {
            $request->setMethod('POST');
        }
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->getSession()->set(SocialMetaPageController::SESSION_PAGES, ['expires' => time() + $expires, 'pages' => $pages]);

        return $request;
    }

    // Same seam as MetaOAuthControllerTest, render() stubbed so the picker can be told from a redirect
    private function createController(Request $request): SocialMetaPageController
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key) => 'site-role-editor' === $key ? 'ROLE_EDITOR' : null);

        $configValueWriter = $this->createStub(ConfigValueWriter::class);
        $configValueWriter->method('write')->willReturnCallback(function (array $values): void {
            if ($this->namesUndeclared && \array_key_exists('social-meta-page-name', $values)) {
                throw new \RuntimeException('The config "social-meta-page-name" is not declared.');
            }
            $this->written[] = $values;
        });

        $controller = new class ($configService, $configValueWriter) extends SocialMetaPageController {
            protected function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                return new Response('picker');
            }
        };
        $controller->setContainer($this->createContainer($request));

        return $controller;
    }

    private function createContainer(Request $request): ContainerInterface
    {
        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn(true);

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => 'valid' === $token->getValue());

        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route): string => '/' . $route);

        $services = [
            'request_stack' => new RequestStack([$request]),
            'security.authorization_checker' => $authorizationChecker,
            'router' => $router,
            'security.csrf.token_manager' => $csrfTokenManager,
        ];

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => isset($services[$id]));
        $container->method('get')->willReturnCallback(static fn (string $id) => $services[$id] ?? null);

        return $container;
    }

    // The flashes by type, a translatable one read as its key
    private function flashes(Request $request): array
    {
        $session = $request->getSession();
        \assert($session instanceof Session);

        return array_map(
            static fn (array $messages): array => array_map(static fn ($message) => $message instanceof TranslatableInterface ? $message->getMessage() : $message, $messages),
            $session->getFlashBag()->all()
        );
    }

    // An account managing a single Page has nothing to pick: stored straight away
    public function testASinglePageIsStoredWithoutAsking(): void
    {
        $request = $this->createRequest([self::PAGE]);

        $response = $this->createController($request)->index($request);

        $this->assertSame([['social-meta-page-id' => '55', 'social-meta-page-token' => 'page-token', 'social-meta-instagram-id' => '77'], ['social-meta-page-name' => 'Papa Câlin', 'social-meta-instagram-username' => 'papacalin']], $this->written);
        $this->assertSame(['success' => ['flash.meta_connected']], $this->flashes($request));
        $this->assertSame('/management_social_connections', $response->getTargetUrl());
    }

    // A site not yet through load-all keeps its connection, the names left out
    public function testUndeclaredNamesDoNotBlockTheConnection(): void
    {
        $this->namesUndeclared = true;
        $request = $this->createRequest([self::PAGE]);

        $response = $this->createController($request)->index($request);

        $this->assertSame([['social-meta-page-id' => '55', 'social-meta-page-token' => 'page-token', 'social-meta-instagram-id' => '77']], $this->written);
        $this->assertSame(['success' => ['flash.meta_connected']], $this->flashes($request));
        $this->assertSame('/management_social_connections', $response->getTargetUrl());
    }

    // A Page with no Instagram account linked still connects: Facebook posts, and the flash says why Instagram will not
    public function testAPageWithoutInstagramSaysSo(): void
    {
        $request = $this->createRequest([self::OTHER_PAGE]);

        $this->createController($request)->index($request);

        $this->assertSame(['success' => ['flash.meta_connected_without_instagram']], $this->flashes($request));
    }

    public function testSeveralPagesShowThePicker(): void
    {
        $request = $this->createRequest();

        $this->assertSame('picker', $this->createController($request)->index($request)->getContent());
        $this->assertSame([], $this->written);
    }

    public function testThePagePickedIsStoredAndDroppedFromTheSession(): void
    {
        $request = $this->createRequest(pageId: '55');

        $response = $this->createController($request)->index($request);

        $this->assertSame([['social-meta-page-id' => '55', 'social-meta-page-token' => 'page-token', 'social-meta-instagram-id' => '77'], ['social-meta-page-name' => 'Papa Câlin', 'social-meta-instagram-username' => 'papacalin']], $this->written);
        $this->assertNull($request->getSession()->get(SocialMetaPageController::SESSION_PAGES));
        $this->assertSame('/management_social_connections', $response->getTargetUrl());
    }

    // A forged form, or a Page the account was not handed, writes nothing and says so
    public function testAPickWithoutAValidTokenOrAPageOfTheListWritesNothing(): void
    {
        foreach ([['55', 'forged'], ['99', 'valid']] as [$pageId, $token]) {
            $request = $this->createRequest(pageId: $pageId, token: $token);

            $this->assertSame('picker', $this->createController($request)->index($request)->getContent());
            $this->assertSame([], $this->written);
            $this->assertSame(['danger' => ['flash.meta_page_invalid']], $this->flashes($request));
        }
    }

    // Reached with no Pages waiting, the pick done already, or the Pages held too long: back to the connections
    public function testNoPagesOrPagesHeldTooLongGoBackToTheConnections(): void
    {
        foreach ([$this->createRequest([]), $this->createRequest(expires: -1)] as $request) {
            $response = $this->createController($request)->index($request);

            $this->assertInstanceOf(RedirectResponse::class, $response);
            $this->assertSame('/management_social_connections', $response->getTargetUrl());
            $this->assertNull($request->getSession()->get(SocialMetaPageController::SESSION_PAGES));
        }
    }

    // An entry of another shape, from a session another version of the code wrote, is left out rather than failing the screen
    public function testAMalformedEntryIsLeftOut(): void
    {
        $request = $this->createRequest(['stale', ['pageId' => '11'], self::PAGE]);

        $this->createController($request)->index($request);

        $this->assertSame([['social-meta-page-id' => '55', 'social-meta-page-token' => 'page-token', 'social-meta-instagram-id' => '77'], ['social-meta-page-name' => 'Papa Câlin', 'social-meta-instagram-username' => 'papacalin']], $this->written);
    }
}
