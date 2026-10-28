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
use c975L\SocialBundle\Controller\LinkedInOAuthController;
use c975L\SocialBundle\Controller\Management\SocialLinkedInPageController;
use c975L\SocialBundle\Service\ConfigValueWriter;
use c975L\SocialBundle\Service\LinkedInClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class LinkedInOAuthControllerTest extends TestCase
{
    use ControllerTestTrait;

    private function createRequest(array $query = []): Request
    {
        $request = new Request($query);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    // Same seam as GoogleOAuthControllerTest: AbstractController resolves security, routing and the flash bag through its container
    private function createController(Request $request, ?LinkedInClient $linkedInClient = null, ?ConfigValueWriter $configValueWriter = null): LinkedInOAuthController
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key) => 'site-role-editor' === $key ? 'ROLE_EDITOR' : null);

        if (null === $linkedInClient) {
            $linkedInClient = $this->createStub(LinkedInClient::class);
            $linkedInClient->method('isConfigured')->willReturn(true);
            $linkedInClient->method('getAuthorizationUrl')->willReturn('https://www.linkedin.com/oauth/v2/authorization?state=x');
        }

        $controller = new LinkedInOAuthController($configService, $configValueWriter ?? $this->createStub(ConfigValueWriter::class), $linkedInClient);
        $controller->setContainer($this->createContainer($request));

        return $controller;
    }

    private function callbackRequest(bool $page = false): Request
    {
        $request = $this->createRequest(['code' => 'auth-code', 'state' => 'expected-state']);
        $request->getSession()->set('social_linkedin_oauth_state', 'expected-state');
        $request->getSession()->set('social_linkedin_oauth_page', $page);

        return $request;
    }

    // A client whose connection answers the given Pages, its mode checked
    /** @param list<array{id: string, name: string}> $organizations */
    private function connectingClient(array $organizations, bool $page = true): LinkedInClient
    {
        $linkedInClient = $this->createMock(LinkedInClient::class);
        $linkedInClient->expects($this->once())->method('connect')->with('auth-code', $this->anything(), $page)->willReturn(['accessToken' => 'token', 'memberId' => 'abc', 'expiresAt' => new \DateTimeImmutable('2026-12-02T10:00:00+01:00'), 'name' => 'Laurent', 'organizations' => $organizations]);

        return $linkedInClient;
    }

    // A writer keeping what it is asked to write
    private function recordingWriter(array &$written): ConfigValueWriter
    {
        $configValueWriter = $this->createStub(ConfigValueWriter::class);
        $configValueWriter->method('write')->willReturnCallback(function (array $values) use (&$written): void {
            $written[] = $values;
        });

        return $configValueWriter;
    }

    public function testConnectStoresAStateAndSendsItAlongToLinkedIn(): void
    {
        $request = $this->createRequest();

        $response = $this->createController($request)->connect($request);

        $this->assertStringStartsWith('https://www.linkedin.com/', $response->getTargetUrl());
        $this->assertNotEmpty($request->getSession()->get('social_linkedin_oauth_state'));
    }

    // "Connecter une Page" asks the Page's consent, and keeps the mode for the callback
    public function testConnectForAPageAsksThePageScopesAndKeepsTheMode(): void
    {
        $linkedInClient = $this->createMock(LinkedInClient::class);
        $linkedInClient->method('isConfigured')->willReturn(true);
        $linkedInClient->expects($this->once())->method('getAuthorizationUrl')->with($this->anything(), $this->anything(), true)->willReturn('https://www.linkedin.com/oauth/v2/authorization?state=x');
        $request = $this->createRequest(['page' => '1']);

        $this->createController($request, $linkedInClient)->connect($request);

        $this->assertTrue($request->getSession()->get('social_linkedin_oauth_page'));
    }

    public function testConnectRefusesWhenTheAppKeysAreMissing(): void
    {
        $linkedInClient = $this->createStub(LinkedInClient::class);
        $linkedInClient->method('isConfigured')->willReturn(false);
        $request = $this->createRequest();

        $response = $this->createController($request, $linkedInClient)->connect($request);

        $this->assertSame('/management_social_connections', $response->getTargetUrl());
        $this->assertSame(['danger' => ['flash.linkedin_not_configured']], $this->flashes($request));
    }

    // A profile's connection stores the token, the member and the end, and empties a Page connected before - or the posts would still go out on it
    public function testCallbackStoresTheTokenTheMemberAndTheEnd(): void
    {
        $written = [];
        $request = $this->callbackRequest();
        $this->createController($request, $this->connectingClient([], false), $this->recordingWriter($written))->callback($request);

        $this->assertSame([
            ['social-linkedin-access-token' => 'token', 'social-linkedin-member-id' => 'abc', 'social-linkedin-token-expires-at' => '2026-12-02T10:00:00+01:00'],
            ['social-linkedin-organization-id' => null, 'social-linkedin-organization-name' => null],
        ], $written);
        $this->assertSame(['success' => ['flash.linkedin_connected']], $this->flashes($request));
        $this->assertNull($request->getSession()->get('social_linkedin_oauth_page'));
    }

    // A site not yet through load-all has no Page to empty: the profile still connects
    public function testCallbackOfAProfileConnectsWithoutThePageConfigs(): void
    {
        $written = [];
        $configValueWriter = $this->createStub(ConfigValueWriter::class);
        $configValueWriter->method('write')->willReturnCallback(function (array $values) use (&$written): void {
            if (\array_key_exists('social-linkedin-organization-id', $values)) {
                throw new \RuntimeException('The config "social-linkedin-organization-id" is not declared.');
            }
            $written[] = $values;
        });

        $request = $this->callbackRequest();
        $this->createController($request, $this->connectingClient([], false), $configValueWriter)->callback($request);

        $this->assertCount(1, $written);
        $this->assertSame(['success' => ['flash.linkedin_connected']], $this->flashes($request));
    }

    // A member administering a single Page has nothing to pick: everything stored at once
    public function testCallbackOfASinglePageStoresEverything(): void
    {
        $written = [];
        $request = $this->callbackRequest(true);
        $response = $this->createController($request, $this->connectingClient([['id' => '111', 'name' => '975L']]), $this->recordingWriter($written))->callback($request);

        $this->assertSame([[
            'social-linkedin-access-token' => 'token',
            'social-linkedin-member-id' => 'abc',
            'social-linkedin-token-expires-at' => '2026-12-02T10:00:00+01:00',
            'social-linkedin-organization-id' => '111',
            'social-linkedin-organization-name' => '975L',
        ]], $written);
        $this->assertSame(['success' => ['flash.linkedin_page_connected']], $this->flashes($request));
        $this->assertSame('/management_social_connections', $response->getTargetUrl());
    }

    // Several Pages: the whole connection held for "Choisir la Page LinkedIn", nothing written until one is picked
    public function testCallbackOfSeveralPagesHoldsTheConnectionForThePicker(): void
    {
        $written = [];
        $request = $this->callbackRequest(true);
        $response = $this->createController($request, $this->connectingClient([['id' => '111', 'name' => '975L'], ['id' => '222', 'name' => 'EIPT']]), $this->recordingWriter($written))->callback($request);

        $this->assertSame([], $written);
        $this->assertSame('/' . SocialLinkedInPageController::ROUTE, $response->getTargetUrl());
        $held = $request->getSession()->get(SocialLinkedInPageController::SESSION_CONNECTION);
        $this->assertGreaterThan(time(), $held['expires']);
        $this->assertSame(['expiresAt' => '2026-12-02T10:00:00+01:00', 'accessToken' => 'token', 'memberId' => 'abc', 'name' => 'Laurent', 'organizations' => [['id' => '111', 'name' => '975L'], ['id' => '222', 'name' => 'EIPT']]], $held['connection']);
    }

    // A member administering no Page is told so, the working connection left as it was
    public function testCallbackOfAMemberAdministeringNoPageWritesNothing(): void
    {
        $configValueWriter = $this->createMock(ConfigValueWriter::class);
        $configValueWriter->expects($this->never())->method('write');

        $request = $this->callbackRequest(true);
        $response = $this->createController($request, $this->connectingClient([]), $configValueWriter)->callback($request);

        $this->assertSame(['danger' => ['flash.linkedin_no_page']], $this->flashes($request));
        $this->assertSame('/management_social_connections', $response->getTargetUrl());
    }

    // A connection held by an earlier one never picked from does not outlive the next
    public function testCallbackDropsAConnectionHeldBefore(): void
    {
        $written = [];
        $request = $this->callbackRequest();
        $request->getSession()->set(SocialLinkedInPageController::SESSION_CONNECTION, ['expires' => time() + 600, 'connection' => []]);

        $this->createController($request, $this->connectingClient([], false), $this->recordingWriter($written))->callback($request);

        $this->assertNull($request->getSession()->get(SocialLinkedInPageController::SESSION_CONNECTION));
    }

    public function testCallbackRefusesAStateTheSessionNeverIssued(): void
    {
        $configValueWriter = $this->createMock(ConfigValueWriter::class);
        $configValueWriter->expects($this->never())->method('write');

        $request = $this->createRequest(['code' => 'auth-code', 'state' => 'forged-state']);
        $request->getSession()->set('social_linkedin_oauth_state', 'expected-state');

        $this->createController($request, configValueWriter: $configValueWriter)->callback($request);

        $this->assertSame(['danger' => ['flash.linkedin_refused']], $this->flashes($request));
    }

    // A consent refused for scopes the app was not granted says the app is the wrong one, nothing being written
    public function testCallbackSaysWhenTheAppIsTheWrongOne(): void
    {
        $configValueWriter = $this->createMock(ConfigValueWriter::class);
        $configValueWriter->expects($this->never())->method('write');

        $request = $this->createRequest(['error' => 'unauthorized_scope_error', 'state' => 'expected-state']);
        $request->getSession()->set('social_linkedin_oauth_state', 'expected-state');

        $this->createController($request, configValueWriter: $configValueWriter)->callback($request);

        $this->assertSame(['danger' => ['flash.linkedin_wrong_app']], $this->flashes($request));
    }

    // A failure on a reconnection leaves the working connection as it was, nothing having been written yet
    public function testCallbackLeavesTheConnectionUntouchedWhenLinkedInRefuses(): void
    {
        $linkedInClient = $this->createStub(LinkedInClient::class);
        $linkedInClient->method('connect')->willThrowException(new \RuntimeException('LinkedIn refused the connection: The code expired'));

        $configValueWriter = $this->createMock(ConfigValueWriter::class);
        $configValueWriter->expects($this->never())->method('write');

        $request = $this->callbackRequest();
        $response = $this->createController($request, $linkedInClient, $configValueWriter)->callback($request);

        $this->assertSame(['danger' => ['LinkedIn refused the connection: The code expired']], $this->flashes($request));
        $this->assertSame('/management_social_connections', $response->getTargetUrl());
    }
}
