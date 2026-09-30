<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Controller;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Controller\BlueskyOAuthController;
use c975L\SocialBundle\Service\BlueskyOAuthClient;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatableInterface;

class BlueskyOAuthControllerTest extends TestCase
{
    private const array PENDING = ['state' => 'expected-state', 'verifier' => 'v', 'dpopKey' => 'k', 'issuer' => 'https://bsky.social', 'tokenEndpoint' => 'https://bsky.social/oauth/token'];

    private function createRequest(array $query = []): Request
    {
        $request = new Request($query);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    // Same seam as MetaOAuthControllerTest: AbstractController resolves security, routing and the flash bag through its container
    private function createController(Request $request, ?BlueskyOAuthClient $oauthClient = null, string $handle = ''): BlueskyOAuthController
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key) => match ($key) {
            'site-role-editor' => 'ROLE_EDITOR',
            'social-bluesky-handle' => $handle,
            default => null,
        });

        $controller = new BlueskyOAuthController($configService, $oauthClient ?? $this->createStub(BlueskyOAuthClient::class));
        $controller->setContainer($this->createContainer($request));

        return $controller;
    }

    private function createContainer(Request $request): ContainerInterface
    {
        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn(true);

        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route): string => '/' . $route);

        $services = [
            'request_stack' => new RequestStack([$request]),
            'security.authorization_checker' => $authorizationChecker,
            'router' => $router,
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

    private function callbackRequest(array $query = ['code' => 'auth-code', 'state' => 'expected-state', 'iss' => 'https://bsky.social']): Request
    {
        $request = $this->createRequest($query);
        $request->getSession()->set('social_bluesky_oauth_pending', self::PENDING);

        return $request;
    }

    // The handle set is the account asked for, and what the callback checks is kept in the session
    public function testConnectAsksForTheHandleSetAndKeepsThePendingAuthorization(): void
    {
        $oauthClient = $this->createMock(BlueskyOAuthClient::class);
        $oauthClient->expects($this->once())->method('startAuthorization')->with('me.bsky.social')->willReturn(['url' => 'https://bsky.social/oauth/authorize?x', 'pending' => self::PENDING]);
        $request = $this->createRequest();

        $response = $this->createController($request, $oauthClient, 'me.bsky.social')->connect($request);

        $this->assertSame('https://bsky.social/oauth/authorize?x', $response->getTargetUrl());
        $this->assertSame(self::PENDING, $request->getSession()->get('social_bluesky_oauth_pending'));
    }

    // No handle lets the owner pick the account on Bluesky's page
    public function testConnectWithoutAHandleAsksForNone(): void
    {
        $oauthClient = $this->createMock(BlueskyOAuthClient::class);
        $oauthClient->expects($this->once())->method('startAuthorization')->with(null)->willReturn(['url' => 'https://bsky.social/oauth/authorize?x', 'pending' => self::PENDING]);
        $request = $this->createRequest();

        $this->createController($request, $oauthClient)->connect($request);
    }

    public function testConnectShowsWhatBlueskyRefused(): void
    {
        $oauthClient = $this->createStub(BlueskyOAuthClient::class);
        $oauthClient->method('startAuthorization')->willThrowException(new \RuntimeException('The Bluesky handle "nobody" cannot be resolved.'));
        $request = $this->createRequest();

        $response = $this->createController($request, $oauthClient)->connect($request);

        $this->assertSame('/management', $response->getTargetUrl());
        $this->assertSame(['danger' => ['The Bluesky handle "nobody" cannot be resolved.']], $this->flashes($request));
    }

    public function testCallbackFinishesTheAuthorizationWithTheCodeAndTheIssuer(): void
    {
        $oauthClient = $this->createMock(BlueskyOAuthClient::class);
        $oauthClient->expects($this->once())->method('finishAuthorization')->with(self::PENDING, 'auth-code', 'https://bsky.social');
        $request = $this->callbackRequest();

        $this->createController($request, $oauthClient)->callback($request);

        $this->assertSame(['success' => ['flash.bluesky_connected']], $this->flashes($request));
        $this->assertNull($request->getSession()->get('social_bluesky_oauth_pending'));
    }

    public function testCallbackRefusesAStateTheSessionNeverIssued(): void
    {
        $oauthClient = $this->createMock(BlueskyOAuthClient::class);
        $oauthClient->expects($this->never())->method('finishAuthorization');
        $request = $this->callbackRequest(['code' => 'auth-code', 'state' => 'forged-state']);

        $this->createController($request, $oauthClient)->callback($request);

        $this->assertSame(['danger' => ['flash.bluesky_refused']], $this->flashes($request));
    }

    // The owner declining on Bluesky's page comes back with no code
    public function testCallbackRefusesAMissingCode(): void
    {
        $oauthClient = $this->createMock(BlueskyOAuthClient::class);
        $oauthClient->expects($this->never())->method('finishAuthorization');
        $request = $this->callbackRequest(['state' => 'expected-state', 'error' => 'access_denied']);

        $this->createController($request, $oauthClient)->callback($request);

        $this->assertSame(['danger' => ['flash.bluesky_refused']], $this->flashes($request));
    }

    public function testCallbackShowsWhyTheConnectionFailed(): void
    {
        $oauthClient = $this->createStub(BlueskyOAuthClient::class);
        $oauthClient->method('finishAuthorization')->willThrowException(new \RuntimeException('Bluesky connected another account than the one asked.'));
        $request = $this->callbackRequest();

        $response = $this->createController($request, $oauthClient)->callback($request);

        $this->assertSame('/management', $response->getTargetUrl());
        $this->assertSame(['danger' => ['Bluesky connected another account than the one asked.']], $this->flashes($request));
    }
}
