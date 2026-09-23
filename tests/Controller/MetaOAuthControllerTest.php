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
use c975L\SocialBundle\Controller\MetaOAuthController;
use c975L\SocialBundle\Service\ConfigValueWriter;
use c975L\SocialBundle\Service\MetaGraphClient;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatableInterface;

class MetaOAuthControllerTest extends TestCase
{
    private function createRequest(array $query = []): Request
    {
        $request = new Request($query);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    // Same seam as GoogleOAuthControllerTest: AbstractController resolves security, routing and the flash bag through its container
    private function createController(Request $request, ?MetaGraphClient $metaGraphClient = null, ?ConfigValueWriter $configValueWriter = null): MetaOAuthController
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key) => 'site-role-editor' === $key ? 'ROLE_EDITOR' : null);

        if (null === $metaGraphClient) {
            $metaGraphClient = $this->createStub(MetaGraphClient::class);
            $metaGraphClient->method('isConfigured')->willReturn(true);
            $metaGraphClient->method('getAuthorizationUrl')->willReturn('https://www.facebook.com/v26.0/dialog/oauth?state=x');
        }

        $controller = new MetaOAuthController($configService, $configValueWriter ?? $this->createStub(ConfigValueWriter::class), $metaGraphClient);
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

    private function callbackRequest(): Request
    {
        $request = $this->createRequest(['code' => 'auth-code', 'state' => 'expected-state']);
        $request->getSession()->set('social_meta_oauth_state', 'expected-state');

        return $request;
    }

    public function testConnectStoresAStateAndSendsItAlongToMeta(): void
    {
        $request = $this->createRequest();

        $response = $this->createController($request)->connect($request);

        $this->assertStringStartsWith('https://www.facebook.com/', $response->getTargetUrl());
        $this->assertNotEmpty($request->getSession()->get('social_meta_oauth_state'));
    }

    public function testConnectRefusesWhenTheAppKeysAreMissing(): void
    {
        $metaGraphClient = $this->createStub(MetaGraphClient::class);
        $metaGraphClient->method('isConfigured')->willReturn(false);
        $request = $this->createRequest();

        $response = $this->createController($request, $metaGraphClient)->connect($request);

        $this->assertSame('/management', $response->getTargetUrl());
        $this->assertSame(['danger' => ['flash.meta_not_configured']], $this->flashes($request));
    }

    public function testCallbackStoresThePageItsTokenAndTheInstagramAccount(): void
    {
        $metaGraphClient = $this->createStub(MetaGraphClient::class);
        $metaGraphClient->method('connect')->willReturn(['pageId' => '55', 'pageToken' => 'page-token', 'instagramId' => '77']);

        $written = [];
        $configValueWriter = $this->createStub(ConfigValueWriter::class);
        $configValueWriter->method('write')->willReturnCallback(function (array $values) use (&$written): void {
            $written[] = $values;
        });

        $request = $this->callbackRequest();
        $this->createController($request, $metaGraphClient, $configValueWriter)->callback($request);

        $this->assertSame([['social-meta-page-id' => '55', 'social-meta-page-token' => 'page-token', 'social-meta-instagram-id' => '77']], $written);
        $this->assertSame(['success' => ['flash.meta_connected']], $this->flashes($request));
    }

    // A Page with no Instagram account linked still connects: Facebook posts, and the flash says why Instagram will not
    public function testCallbackSaysWhenNoInstagramAccountIsLinked(): void
    {
        $metaGraphClient = $this->createStub(MetaGraphClient::class);
        $metaGraphClient->method('connect')->willReturn(['pageId' => '55', 'pageToken' => 'page-token', 'instagramId' => null]);

        $request = $this->callbackRequest();
        $this->createController($request, $metaGraphClient)->callback($request);

        $this->assertSame(['success' => ['flash.meta_connected_without_instagram']], $this->flashes($request));
    }

    public function testCallbackRefusesAStateTheSessionNeverIssued(): void
    {
        $configValueWriter = $this->createMock(ConfigValueWriter::class);
        $configValueWriter->expects($this->never())->method('write');

        $request = $this->createRequest(['code' => 'auth-code', 'state' => 'forged-state']);
        $request->getSession()->set('social_meta_oauth_state', 'expected-state');

        $this->createController($request, configValueWriter: $configValueWriter)->callback($request);

        $this->assertSame(['danger' => ['flash.meta_refused']], $this->flashes($request));
    }

    // A failure on a reconnection leaves the working connection as it was, nothing having been written yet
    public function testCallbackLeavesTheConnectionUntouchedWhenMetaRefuses(): void
    {
        $metaGraphClient = $this->createStub(MetaGraphClient::class);
        $metaGraphClient->method('connect')->willThrowException(new \RuntimeException('This Facebook account manages no Page: the posts need one to go out under.'));

        $configValueWriter = $this->createMock(ConfigValueWriter::class);
        $configValueWriter->expects($this->never())->method('write');

        $request = $this->callbackRequest();
        $response = $this->createController($request, $metaGraphClient, $configValueWriter)->callback($request);

        $this->assertSame(['danger' => ['This Facebook account manages no Page: the posts need one to go out under.']], $this->flashes($request));
        $this->assertSame('/management', $response->getTargetUrl());
    }
}
