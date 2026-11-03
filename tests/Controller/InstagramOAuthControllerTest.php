<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Controller;

use c975L\SocialBundle\Controller\InstagramOAuthController;
use c975L\SocialBundle\Service\ConfigValueWriter;
use c975L\SocialBundle\Service\InstagramLoginClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class InstagramOAuthControllerTest extends TestCase
{
    use ControllerTestTrait;

    // Same seam as PinterestOAuthControllerTest
    private function createController(Request $request, ?InstagramLoginClient $client = null, ?ConfigValueWriter $configValueWriter = null): InstagramOAuthController
    {
        $controller = new InstagramOAuthController($this->configService([]), $configValueWriter ?? $this->createStub(ConfigValueWriter::class), $client ?? $this->oauthClient(InstagramLoginClient::class, 'https://www.instagram.com/oauth/authorize?state=x'));
        $controller->setContainer($this->createContainer($request));

        return $controller;
    }

    private function callbackRequest(string $state = 'expected-state'): Request
    {
        return $this->oauthCallbackRequest('social_instagram_oauth_state', $state);
    }

    public function testConnectStoresAStateAndSendsItAlongToInstagram(): void
    {
        $request = $this->createRequest();

        $response = $this->createController($request)->connect($request);

        $this->assertStringStartsWith('https://www.instagram.com/', $response->getTargetUrl());
        $this->assertNotEmpty($request->getSession()->get('social_instagram_oauth_state'));
    }

    public function testConnectRefusesWhenTheAppKeysAreMissing(): void
    {
        $client = $this->createStub(InstagramLoginClient::class);
        $client->method('isConfigured')->willReturn(false);
        $request = $this->createRequest();

        $response = $this->createController($request, $client)->connect($request);

        $this->assertSame('/management_social_connections', $response->getTargetUrl());
        $this->assertSame(['danger' => ['flash.instagram_not_configured']], $this->flashes($request));
    }

    // The token and the account stored, then back on the connections
    public function testCallbackStoresTheTokenAndTheAccount(): void
    {
        $client = $this->createMock(InstagramLoginClient::class);
        $client->expects($this->once())->method('connect')->with('auth-code', $this->anything())->willReturn(['social-instagram-access-token' => 'ig', 'social-instagram-username' => 'catalog']);
        $written = [];
        $request = $this->callbackRequest();

        $response = $this->createController($request, $client, $this->recordingWriter($written))->callback($request);

        $this->assertSame([['social-instagram-access-token' => 'ig', 'social-instagram-username' => 'catalog']], $written);
        $this->assertSame('/management_social_connections', $response->getTargetUrl());
        $this->assertSame(['success' => ['flash.instagram_connected']], $this->flashes($request));
    }

    // A state that does not match is refused before any code is traded
    public function testCallbackRefusesAStateThatDoesNotMatch(): void
    {
        $client = $this->createMock(InstagramLoginClient::class);
        $client->expects($this->never())->method('connect');
        $request = $this->callbackRequest('forged');

        $response = $this->createController($request, $client)->callback($request);

        $this->assertSame('/management_social_connections', $response->getTargetUrl());
        $this->assertSame(['danger' => ['flash.instagram_refused']], $this->flashes($request));
    }

    // Instagram's refusal said as it is, nothing written
    public function testCallbackSaysInstagramsRefusal(): void
    {
        $client = $this->createStub(InstagramLoginClient::class);
        $client->method('connect')->willThrowException(new \RuntimeException('Meta refused /oauth/access_token: Invalid code'));
        $configValueWriter = $this->createMock(ConfigValueWriter::class);
        $configValueWriter->expects($this->never())->method('write');
        $request = $this->callbackRequest();

        $this->createController($request, $client, $configValueWriter)->callback($request);

        $this->assertSame(['danger' => ['Meta refused /oauth/access_token: Invalid code']], $this->flashes($request));
    }
}
