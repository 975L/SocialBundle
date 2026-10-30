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
use c975L\SocialBundle\Controller\Management\SocialPinterestBoardController;
use c975L\SocialBundle\Controller\PinterestOAuthController;
use c975L\SocialBundle\Service\ConfigValueWriter;
use c975L\SocialBundle\Service\PinterestClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class PinterestOAuthControllerTest extends TestCase
{
    use ControllerTestTrait;

    private function createRequest(array $query = []): Request
    {
        $request = new Request($query);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    // Same seam as LinkedInOAuthControllerTest
    private function createController(Request $request, ?PinterestClient $pinterestClient = null, ?ConfigValueWriter $configValueWriter = null, ?string $username = null): PinterestOAuthController
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key) => ['site-role-editor' => 'ROLE_EDITOR', 'social-pinterest-username' => $username][$key] ?? null);

        if (null === $pinterestClient) {
            $pinterestClient = $this->createStub(PinterestClient::class);
            $pinterestClient->method('isConfigured')->willReturn(true);
            $pinterestClient->method('getAuthorizationUrl')->willReturn('https://www.pinterest.com/oauth/?state=x');
        }

        $controller = new PinterestOAuthController($configService, $configValueWriter ?? $this->createStub(ConfigValueWriter::class), $pinterestClient);
        $controller->setContainer($this->createContainer($request));

        return $controller;
    }

    private function callbackRequest(string $state = 'expected-state'): Request
    {
        $request = $this->createRequest(['code' => 'auth-code', 'state' => $state]);
        $request->getSession()->set('social_pinterest_oauth_state', 'expected-state');

        return $request;
    }

    public function testConnectStoresAStateAndSendsItAlongToPinterest(): void
    {
        $request = $this->createRequest();

        $response = $this->createController($request)->connect($request);

        $this->assertStringStartsWith('https://www.pinterest.com/', $response->getTargetUrl());
        $this->assertNotEmpty($request->getSession()->get('social_pinterest_oauth_state'));
    }

    public function testConnectRefusesWhenTheAppKeysAreMissing(): void
    {
        $pinterestClient = $this->createStub(PinterestClient::class);
        $pinterestClient->method('isConfigured')->willReturn(false);
        $request = $this->createRequest();

        $response = $this->createController($request, $pinterestClient)->connect($request);

        $this->assertSame('/management_social_connections', $response->getTargetUrl());
        $this->assertSame(['danger' => ['flash.pinterest_not_configured']], $this->flashes($request));
    }

    // A callback connecting "papacalin", the account stored before it given, answering what it wrote and where it went
    /** @return array{list<array<string, mixed>>, RedirectResponse, Request} */
    private function connectPapacalin(?string $storedUsername = null): array
    {
        $pinterestClient = $this->createMock(PinterestClient::class);
        $pinterestClient->expects($this->once())->method('connect')->with('auth-code', $this->anything())->willReturn(['social-pinterest-access-token' => 'pina', 'social-pinterest-username' => 'papacalin']);
        $written = [];
        $configValueWriter = $this->createStub(ConfigValueWriter::class);
        $configValueWriter->method('write')->willReturnCallback(function (array $values) use (&$written): void {
            $written[] = $values;
        });

        $request = $this->callbackRequest();
        $response = $this->createController($request, $pinterestClient, $configValueWriter, $storedUsername)->callback($request);

        return [$written, $response, $request];
    }

    // The same account connected again keeps its board
    public function testCallbackKeepsTheBoardOfTheSameAccount(): void
    {
        [$written] = $this->connectPapacalin('papacalin');

        $this->assertSame([['social-pinterest-access-token' => 'pina', 'social-pinterest-username' => 'papacalin']], $written);
    }

    // The tokens and the account stored, the board emptied - another account's would not take the pins - then the board picked
    public function testCallbackStoresTheTokensAndGoesOnToTheBoard(): void
    {
        [$written, $response, $request] = $this->connectPapacalin();

        $this->assertSame([['social-pinterest-access-token' => 'pina', 'social-pinterest-username' => 'papacalin', 'social-pinterest-board-id' => null, 'social-pinterest-board-name' => null]], $written);
        $this->assertSame('/' . SocialPinterestBoardController::ROUTE, $response->getTargetUrl());
        $this->assertSame(['success' => ['flash.pinterest_connected']], $this->flashes($request));
    }

    // A state that does not match is refused before any code is traded
    public function testCallbackRefusesAStateThatDoesNotMatch(): void
    {
        $pinterestClient = $this->createMock(PinterestClient::class);
        $pinterestClient->expects($this->never())->method('connect');
        $request = $this->callbackRequest('forged');

        $response = $this->createController($request, $pinterestClient)->callback($request);

        $this->assertSame('/management_social_connections', $response->getTargetUrl());
        $this->assertSame(['danger' => ['flash.pinterest_refused']], $this->flashes($request));
    }

    // Pinterest's refusal said as it is, nothing written
    public function testCallbackSaysPinterestsRefusal(): void
    {
        $pinterestClient = $this->createStub(PinterestClient::class);
        $pinterestClient->method('connect')->willThrowException(new \RuntimeException('Pinterest refused the connection: Invalid code'));
        $configValueWriter = $this->createMock(ConfigValueWriter::class);
        $configValueWriter->expects($this->never())->method('write');
        $request = $this->callbackRequest();

        $this->createController($request, $pinterestClient, $configValueWriter)->callback($request);

        $this->assertSame(['danger' => ['Pinterest refused the connection: Invalid code']], $this->flashes($request));
    }
}
