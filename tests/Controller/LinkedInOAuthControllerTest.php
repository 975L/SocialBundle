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
use c975L\SocialBundle\Service\ConfigValueWriter;
use c975L\SocialBundle\Service\LinkedInClient;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatableInterface;

class LinkedInOAuthControllerTest extends TestCase
{
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
        $request->getSession()->set('social_linkedin_oauth_state', 'expected-state');

        return $request;
    }

    public function testConnectStoresAStateAndSendsItAlongToLinkedIn(): void
    {
        $request = $this->createRequest();

        $response = $this->createController($request)->connect($request);

        $this->assertStringStartsWith('https://www.linkedin.com/', $response->getTargetUrl());
        $this->assertNotEmpty($request->getSession()->get('social_linkedin_oauth_state'));
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

    public function testCallbackStoresTheTokenTheMemberAndTheEnd(): void
    {
        $linkedInClient = $this->createStub(LinkedInClient::class);
        $linkedInClient->method('connect')->willReturn(['accessToken' => 'token', 'memberId' => 'abc', 'expiresAt' => new \DateTimeImmutable('2026-12-02T10:00:00+01:00'), 'name' => 'Laurent']);

        $written = [];
        $configValueWriter = $this->createStub(ConfigValueWriter::class);
        $configValueWriter->method('write')->willReturnCallback(function (array $values) use (&$written): void {
            $written[] = $values;
        });

        $request = $this->callbackRequest();
        $this->createController($request, $linkedInClient, $configValueWriter)->callback($request);

        $this->assertSame([['social-linkedin-access-token' => 'token', 'social-linkedin-member-id' => 'abc', 'social-linkedin-token-expires-at' => '2026-12-02T10:00:00+01:00']], $written);
        $this->assertSame(['success' => ['flash.linkedin_connected']], $this->flashes($request));
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
