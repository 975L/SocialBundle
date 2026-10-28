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
use c975L\SocialBundle\Controller\Management\SocialLinkedInPageController;
use c975L\SocialBundle\Service\ConfigValueWriter;
use c975L\SocialBundle\Tests\Controller\ControllerTestTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class SocialLinkedInPageControllerTest extends TestCase
{
    use ControllerTestTrait;

    private const array PAGES = [['id' => '111', 'name' => '975L'], ['id' => '222', 'name' => 'EIPT']];

    /** @var list<array<string, ?string>> */
    private array $written = [];

    // The connection held as the callback holds it, posted to when an organization id is given
    private function createRequest(array $organizations = self::PAGES, ?string $organizationId = null, string $token = 'valid', int $expires = 600): Request
    {
        $request = null === $organizationId ? new Request() : new Request([], ['organization' => $organizationId, 'token' => $token]);
        if (null !== $organizationId) {
            $request->setMethod('POST');
        }
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->getSession()->set(SocialLinkedInPageController::SESSION_CONNECTION, ['expires' => time() + $expires, 'connection' => [
            'expiresAt' => '2026-12-02T10:00:00+01:00',
            'accessToken' => 'token',
            'memberId' => 'abc',
            'name' => 'Laurent',
            'organizations' => $organizations,
        ]]);

        return $request;
    }

    // Same seam as SocialMetaPageControllerTest, render() stubbed so the picker can be told from a redirect
    private function createController(Request $request): SocialLinkedInPageController
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key) => ['site-role-editor' => 'ROLE_EDITOR', 'social-linkedin-organization-id' => '222'][$key] ?? null);

        $configValueWriter = $this->createStub(ConfigValueWriter::class);
        $configValueWriter->method('write')->willReturnCallback(function (array $values): void {
            $this->written[] = $values;
        });

        $controller = new class ($configService, $configValueWriter) extends SocialLinkedInPageController {
            /** @var array<string, mixed> */
            public array $rendered = [];

            protected function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                $this->rendered = $parameters;

                return new Response('picker');
            }
        };
        $controller->setContainer($this->createContainer($request));

        return $controller;
    }

    // The Pages offered, the current one named for the screen to flag it, nothing written yet
    public function testThePagesAreOfferedWithTheCurrentOne(): void
    {
        $request = $this->createRequest();

        $controller = $this->createController($request);

        $this->assertSame('picker', $controller->index($request)->getContent());
        $this->assertSame([], $this->written);
        $this->assertSame(self::PAGES, $controller->rendered['organizations']);
        $this->assertSame('222', $controller->rendered['current']);
    }

    // The Page picked written at once with the token, the member and the end, the connection dropped from the session
    public function testThePagePickedIsStoredWithTheConnection(): void
    {
        $request = $this->createRequest(organizationId: '111');

        $response = $this->createController($request)->index($request);

        $this->assertSame([[
            'social-linkedin-access-token' => 'token',
            'social-linkedin-member-id' => 'abc',
            'social-linkedin-token-expires-at' => '2026-12-02T10:00:00+01:00',
            'social-linkedin-organization-id' => '111',
            'social-linkedin-organization-name' => '975L',
        ]], $this->written);
        $this->assertSame(['success' => ['flash.linkedin_page_connected']], $this->flashes($request));
        $this->assertNull($request->getSession()->get(SocialLinkedInPageController::SESSION_CONNECTION));
        $this->assertSame('/management_social_connections', $response->getTargetUrl());
    }

    // A forged form, or a Page the member does not administer, writes nothing and says so
    public function testAPickWithoutAValidTokenOrAPageOfTheListWritesNothing(): void
    {
        foreach ([['111', 'forged'], ['999', 'valid']] as [$organizationId, $token]) {
            $request = $this->createRequest(organizationId: $organizationId, token: $token);

            $this->assertSame('picker', $this->createController($request)->index($request)->getContent());
            $this->assertSame([], $this->written);
            $this->assertSame(['danger' => ['flash.linkedin_page_invalid']], $this->flashes($request));
        }
    }

    // Reached with no connection waiting, the pick done already, or the connection held too long: back to the connections
    public function testNoConnectionOrOneHeldTooLongGoesBackToTheConnections(): void
    {
        foreach ([$this->createRequest([]), $this->createRequest(expires: -1)] as $request) {
            $response = $this->createController($request)->index($request);

            $this->assertInstanceOf(RedirectResponse::class, $response);
            $this->assertSame('/management_social_connections', $response->getTargetUrl());
            $this->assertNull($request->getSession()->get(SocialLinkedInPageController::SESSION_CONNECTION));
        }
        $this->assertSame([], $this->written);
    }

    // A Page of another shape, from a session another version of the code wrote, is left out rather than failing the screen
    public function testAMalformedPageIsLeftOut(): void
    {
        $request = $this->createRequest(['stale', ['id' => '333'], ...self::PAGES]);

        $controller = $this->createController($request);
        $controller->index($request);

        $this->assertSame(self::PAGES, $controller->rendered['organizations']);
    }
}
