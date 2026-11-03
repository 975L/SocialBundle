<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Controller;

use c975L\SocialBundle\Controller\TikTokOAuthController;
use c975L\SocialBundle\Service\ConfigValueWriter;
use c975L\SocialBundle\Service\TikTokClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class TikTokOAuthControllerTest extends TestCase
{
    use ControllerTestTrait;

    // Same seam as PinterestOAuthControllerTest
    private function createController(Request $request, ?TikTokClient $tikTokClient = null, ?ConfigValueWriter $configValueWriter = null): TikTokOAuthController
    {
        $controller = new TikTokOAuthController($this->configService(), $configValueWriter ?? $this->createStub(ConfigValueWriter::class), $tikTokClient ?? $this->oauthClient(TikTokClient::class, 'https://www.tiktok.com/v2/auth/authorize/?state=x'));
        $controller->setContainer($this->createContainer($request));

        return $controller;
    }

    private function callbackRequest(string $state = 'expected-state'): Request
    {
        return $this->oauthCallbackRequest('social_tiktok_oauth_state', $state);
    }

    public function testConnectStoresAStateAndSendsItAlongToTikTok(): void
    {
        $request = $this->createRequest();

        $response = $this->createController($request)->connect($request);

        $this->assertStringStartsWith('https://www.tiktok.com/', $response->getTargetUrl());
        $this->assertNotEmpty($request->getSession()->get('social_tiktok_oauth_state'));
    }

    public function testConnectRefusesWhenTheAppKeysAreMissing(): void
    {
        $tikTokClient = $this->createStub(TikTokClient::class);
        $tikTokClient->method('isConfigured')->willReturn(false);
        $request = $this->createRequest();

        $response = $this->createController($request, $tikTokClient)->connect($request);

        $this->assertSame('/management_social_connections', $response->getTargetUrl());
        $this->assertSame(['danger' => ['flash.tiktok_not_configured']], $this->flashes($request));
    }

    // The tokens and the account stored, then back to "Connexions"
    public function testCallbackStoresTheTokensAndTheAccount(): void
    {
        $tikTokClient = $this->createMock(TikTokClient::class);
        $tikTokClient->expects($this->once())->method('connect')->with('auth-code', $this->anything())->willReturn(['social-tiktok-access-token' => 'act', 'social-tiktok-display-name' => 'Mystic Fable Studios']);
        $written = [];
        $configValueWriter = $this->recordingWriter($written);
        $request = $this->callbackRequest();

        $response = $this->createController($request, $tikTokClient, $configValueWriter)->callback($request);

        $this->assertSame([['social-tiktok-access-token' => 'act', 'social-tiktok-display-name' => 'Mystic Fable Studios']], $written);
        $this->assertSame('/management_social_connections', $response->getTargetUrl());
        $this->assertSame(['success' => ['flash.tiktok_connected']], $this->flashes($request));
    }

    // A state that does not match is refused before any code is traded
    public function testCallbackRefusesAStateThatDoesNotMatch(): void
    {
        $tikTokClient = $this->createMock(TikTokClient::class);
        $tikTokClient->expects($this->never())->method('connect');
        $request = $this->callbackRequest('forged');

        $this->createController($request, $tikTokClient)->callback($request);

        $this->assertSame(['danger' => ['flash.tiktok_refused']], $this->flashes($request));
    }

    // TikTok's refusal said as it is, nothing written
    public function testCallbackSaysTikToksRefusal(): void
    {
        $tikTokClient = $this->createStub(TikTokClient::class);
        $tikTokClient->method('connect')->willThrowException(new \RuntimeException('TikTok refused the connection: Authorization code is expired.'));
        $configValueWriter = $this->createMock(ConfigValueWriter::class);
        $configValueWriter->expects($this->never())->method('write');
        $request = $this->callbackRequest();

        $this->createController($request, $tikTokClient, $configValueWriter)->callback($request);

        $this->assertSame(['danger' => ['TikTok refused the connection: Authorization code is expired.']], $this->flashes($request));
    }
}
