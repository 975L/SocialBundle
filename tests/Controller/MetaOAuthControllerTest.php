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
use c975L\SocialBundle\Controller\Management\SocialMetaPageController;
use c975L\SocialBundle\Controller\MetaOAuthController;
use c975L\SocialBundle\Service\MetaGraphClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class MetaOAuthControllerTest extends TestCase
{
    use ControllerTestTrait;

    private const array PAGE = ['pageId' => '55', 'name' => 'Papa Câlin', 'pageToken' => 'page-token', 'instagramId' => '77', 'instagram' => 'papacalin'];

    private const array OTHER_PAGE = ['pageId' => '11', 'name' => 'Other', 'pageToken' => 'other-token', 'instagramId' => null, 'instagram' => null];

    private function createRequest(array $query = []): Request
    {
        $request = new Request($query);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    // Same seam as GoogleOAuthControllerTest: AbstractController resolves security, routing and the flash bag through its container
    private function createController(Request $request, ?MetaGraphClient $metaGraphClient = null): MetaOAuthController
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key) => 'site-role-editor' === $key ? 'ROLE_EDITOR' : null);

        if (null === $metaGraphClient) {
            $metaGraphClient = $this->createStub(MetaGraphClient::class);
            $metaGraphClient->method('isConfigured')->willReturn(true);
            $metaGraphClient->method('getAuthorizationUrl')->willReturn('https://www.facebook.com/v26.0/dialog/oauth?state=x');
        }

        $controller = new MetaOAuthController($configService, $metaGraphClient);
        $controller->setContainer($this->createContainer($request));

        return $controller;
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

        $this->assertSame('/management_social_connections', $response->getTargetUrl());
        $this->assertSame(['danger' => ['flash.meta_not_configured']], $this->flashes($request));
    }

    // Every Page the account manages held in the session, for a short while, and nothing written: the Page screen stores the site's one
    public function testCallbackHoldsThePagesForThePageScreen(): void
    {
        $metaGraphClient = $this->createStub(MetaGraphClient::class);
        $metaGraphClient->method('connect')->willReturn([self::OTHER_PAGE, self::PAGE]);

        $request = $this->callbackRequest();
        $response = $this->createController($request, $metaGraphClient)->callback($request);

        $held = $request->getSession()->get(SocialMetaPageController::SESSION_PAGES);
        $this->assertSame('/' . SocialMetaPageController::ROUTE, $response->getTargetUrl());
        $this->assertSame([self::OTHER_PAGE, self::PAGE], $held['pages']);
        $this->assertGreaterThan(time(), $held['expires']);
    }

    public function testCallbackRefusesAStateTheSessionNeverIssued(): void
    {
        $metaGraphClient = $this->createMock(MetaGraphClient::class);
        $metaGraphClient->expects($this->never())->method('connect');

        $request = $this->createRequest(['code' => 'auth-code', 'state' => 'forged-state']);
        $request->getSession()->set('social_meta_oauth_state', 'expected-state');

        $this->createController($request, $metaGraphClient)->callback($request);

        $this->assertSame(['danger' => ['flash.meta_refused']], $this->flashes($request));
    }

    // A failure on a reconnection leaves the working connection as it was, and drops the Pages an earlier connection left unpicked
    public function testCallbackLeavesTheConnectionUntouchedWhenMetaRefuses(): void
    {
        $metaGraphClient = $this->createStub(MetaGraphClient::class);
        $metaGraphClient->method('connect')->willThrowException(new \RuntimeException('This Facebook account manages no Page: the posts need one to go out under.'));

        $request = $this->callbackRequest();
        $request->getSession()->set(SocialMetaPageController::SESSION_PAGES, ['expires' => time() + 600, 'pages' => [self::OTHER_PAGE, self::PAGE]]);
        $response = $this->createController($request, $metaGraphClient)->callback($request);

        $this->assertSame(['danger' => ['This Facebook account manages no Page: the posts need one to go out under.']], $this->flashes($request));
        $this->assertSame('/management_social_connections', $response->getTargetUrl());
        $this->assertNull($request->getSession()->get(SocialMetaPageController::SESSION_PAGES));
    }
}
