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
use c975L\SocialBundle\Controller\YoutubeOAuthController;
use c975L\SocialBundle\Service\ConfigValueWriter;
use c975L\SocialBundle\Service\YoutubeClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class YoutubeOAuthControllerTest extends TestCase
{
    use ControllerTestTrait;

    private function createRequest(array $query = []): Request
    {
        $request = new Request($query);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    // Same seam as GoogleOAuthControllerTest: AbstractController resolves security, routing and the flash bag through its container
    private function createController(Request $request, ?YoutubeClient $youtubeClient = null, ?ConfigValueWriter $configValueWriter = null): YoutubeOAuthController
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key) => 'site-role-editor' === $key ? 'ROLE_EDITOR' : null);

        if (null === $youtubeClient) {
            $youtubeClient = $this->createStub(YoutubeClient::class);
            $youtubeClient->method('isConfigured')->willReturn(true);
            $youtubeClient->method('getAuthorizationUrl')->willReturn('https://accounts.google.com/o/oauth2/v2/auth?state=x');
        }

        $controller = new YoutubeOAuthController($configService, $configValueWriter ?? $this->createStub(ConfigValueWriter::class), $youtubeClient);
        $controller->setContainer($this->createContainer($request));

        return $controller;
    }

    private function callbackRequest(): Request
    {
        $request = $this->createRequest(['code' => 'auth-code', 'state' => 'expected-state']);
        $request->getSession()->set('social_youtube_oauth_state', 'expected-state');

        return $request;
    }

    public function testConnectStoresAStateAndSendsItAlongToGoogle(): void
    {
        $request = $this->createRequest();

        $response = $this->createController($request)->connect($request);

        $this->assertStringStartsWith('https://accounts.google.com/', $response->getTargetUrl());
        $this->assertNotEmpty($request->getSession()->get('social_youtube_oauth_state'));
    }

    // The Google OAuth client's keys are the ones the site needs, YouTube having no app of its own
    public function testConnectRefusesWhenTheAppKeysAreMissing(): void
    {
        $youtubeClient = $this->createStub(YoutubeClient::class);
        $youtubeClient->method('isConfigured')->willReturn(false);
        $request = $this->createRequest();

        $response = $this->createController($request, $youtubeClient)->connect($request);

        $this->assertSame('/management_social_connections', $response->getTargetUrl());
        $this->assertSame(['danger' => ['flash.youtube_not_configured']], $this->flashes($request));
    }

    public function testCallbackStoresTheTokenAndTheChannel(): void
    {
        $youtubeClient = $this->createStub(YoutubeClient::class);
        $youtubeClient->method('connect')->willReturn(['refreshToken' => 'refresh', 'channelId' => 'UC123', 'channelTitle' => 'Papa Câlin']);

        $written = [];
        $configValueWriter = $this->createStub(ConfigValueWriter::class);
        $configValueWriter->method('write')->willReturnCallback(function (array $values) use (&$written): void {
            $written[] = $values;
        });

        $request = $this->callbackRequest();
        $this->createController($request, $youtubeClient, $configValueWriter)->callback($request);

        $this->assertSame([['social-youtube-oauth-refresh-token' => 'refresh', 'social-youtube-channel-id' => 'UC123', 'social-youtube-channel-title' => 'Papa Câlin']], $written);
        $this->assertSame(['success' => ['flash.youtube_connected']], $this->flashes($request));
    }

    public function testCallbackRefusesAStateTheSessionNeverIssued(): void
    {
        $configValueWriter = $this->createMock(ConfigValueWriter::class);
        $configValueWriter->expects($this->never())->method('write');

        $request = $this->createRequest(['code' => 'auth-code', 'state' => 'forged-state']);
        $request->getSession()->set('social_youtube_oauth_state', 'expected-state');

        $this->createController($request, configValueWriter: $configValueWriter)->callback($request);

        $this->assertSame(['danger' => ['flash.youtube_refused']], $this->flashes($request));
    }

    // A failure on a reconnection leaves the working connection as it was, nothing having been written yet
    public function testCallbackLeavesTheConnectionUntouchedWhenGoogleRefuses(): void
    {
        $youtubeClient = $this->createStub(YoutubeClient::class);
        $youtubeClient->method('connect')->willThrowException(new \RuntimeException('Google refused the token request (400): invalid_grant'));

        $configValueWriter = $this->createMock(ConfigValueWriter::class);
        $configValueWriter->expects($this->never())->method('write');

        $request = $this->callbackRequest();
        $response = $this->createController($request, $youtubeClient, $configValueWriter)->callback($request);

        $this->assertSame(['danger' => ['Google refused the token request (400): invalid_grant']], $this->flashes($request));
        $this->assertSame('/management_social_connections', $response->getTargetUrl());
    }
}
