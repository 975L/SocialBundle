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
use c975L\SocialBundle\Controller\GoogleOAuthController;
use c975L\SocialBundle\Service\ConfigValueWriter;
use c975L\SocialBundle\Service\GoogleBusinessLocationResolver;
use c975L\SocialBundle\Service\GoogleOAuthClient;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class GoogleOAuthControllerTest extends TestCase
{
    private function createRequest(array $query = []): Request
    {
        $request = new Request($query);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    // AbstractController resolves security, routing and the flash bag through its container, so a controller exercised outside the kernel has to be handed one
    private function createController(
        Request $request,
        ?GoogleOAuthClient $googleOAuthClient = null,
        ?ConfigValueWriter $configValueWriter = null,
        ?GoogleBusinessLocationResolver $googleBusinessLocationResolver = null,
    ): GoogleOAuthController {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(
            static fn (string $key) => 'site-role-editor' === $key ? 'ROLE_ADMIN' : null
        );

        if (null === $googleOAuthClient) {
            $googleOAuthClient = $this->createStub(GoogleOAuthClient::class);
            $googleOAuthClient->method('isConfigured')->willReturn(true);
            $googleOAuthClient->method('getAuthorizationUrl')->willReturn('https://accounts.google.com/o/oauth2/v2/auth?state=x');
        }

        $controller = new GoogleOAuthController(
            $configService,
            $configValueWriter ?? $this->createStub(ConfigValueWriter::class),
            $googleOAuthClient,
            $googleBusinessLocationResolver ?? $this->createStub(GoogleBusinessLocationResolver::class),
        );

        $controller->setContainer($this->createContainer($request));

        return $controller;
    }

    private function createContainer(Request $request): ContainerInterface
    {
        $requestStack = new RequestStack([$request]);

        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn(true);

        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route): string => '/' . $route);

        $services = [
            'request_stack' => $requestStack,
            'security.authorization_checker' => $authorizationChecker,
            'router' => $router,
        ];

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => isset($services[$id]));
        $container->method('get')->willReturnCallback(static fn (string $id) => $services[$id] ?? null);

        return $container;
    }

    private function flashes(Request $request): array
    {
        $session = $request->getSession();
        \assert($session instanceof Session);

        return $session->getFlashBag()->all();
    }

    // Without the state held in the session, the callback would accept a code obtained by anyone able to make the editor's browser follow a link
    public function testConnectStoresAStateAndSendsItAlongToGoogle(): void
    {
        $request = $this->createRequest();

        $response = $this->createController($request)->connect($request);

        $this->assertStringStartsWith('https://accounts.google.com/', $response->getTargetUrl());
        $this->assertNotEmpty($request->getSession()->get('social_google_oauth_state'));
    }

    // A site whose client id and secret are not filled in has nothing to send Google, and says so rather than opening a consent screen that would refuse it
    public function testConnectRefusesWhenTheCredentialsAreMissing(): void
    {
        $googleOAuthClient = $this->createStub(GoogleOAuthClient::class);
        $googleOAuthClient->method('isConfigured')->willReturn(false);
        $request = $this->createRequest();

        $response = $this->createController($request, $googleOAuthClient)->connect($request);

        $this->assertSame('/management', $response->getTargetUrl());
        $this->assertSame(['danger' => ['review.google_not_configured']], $this->flashes($request));
    }

    public function testCallbackStoresTheRefreshTokenThenTheResolvedListing(): void
    {
        $googleOAuthClient = $this->createStub(GoogleOAuthClient::class);
        $googleOAuthClient->method('exchangeCode')->willReturn('refresh-token');

        $resolver = $this->createStub(GoogleBusinessLocationResolver::class);
        $resolver->method('resolveFirst')->willReturn(['accountId' => '123', 'locationId' => '456']);

        $written = [];
        $configValueWriter = $this->createStub(ConfigValueWriter::class);
        $configValueWriter->method('write')->willReturnCallback(function (array $values) use (&$written): void {
            $written[] = $values;
        });

        $request = $this->createRequest(['code' => 'auth-code', 'state' => 'expected-state']);
        $request->getSession()->set('social_google_oauth_state', 'expected-state');

        $response = $this->createController($request, $googleOAuthClient, $configValueWriter, $resolver)->callback($request);

        // The token is written first on purpose: resolving the listing needs an access token, minted from that very refresh token
        $this->assertSame([
            ['social-google-oauth-refresh-token' => 'refresh-token'],
            ['social-google-business-account-id' => '123', 'social-google-business-location-id' => '456'],
        ], $written);
        $this->assertSame(['success' => ['review.google_connected']], $this->flashes($request));
        $this->assertSame('/management', $response->getTargetUrl());
    }

    // A code arriving with a state the session never issued is the forged callback the state exists to catch
    public function testCallbackRefusesAStateTheSessionNeverIssued(): void
    {
        $configValueWriter = $this->createMock(ConfigValueWriter::class);
        $configValueWriter->expects($this->never())->method('write');

        $request = $this->createRequest(['code' => 'auth-code', 'state' => 'forged-state']);
        $request->getSession()->set('social_google_oauth_state', 'expected-state');

        $this->createController($request, configValueWriter: $configValueWriter)->callback($request);

        $this->assertSame(['danger' => ['review.google_state_mismatch']], $this->flashes($request));
    }

    // Replaying the callback finds no state left, the first pass having consumed it
    public function testCallbackRefusesWhenNoStateIsHeldAtAll(): void
    {
        $configValueWriter = $this->createMock(ConfigValueWriter::class);
        $configValueWriter->expects($this->never())->method('write');

        $request = $this->createRequest(['code' => 'auth-code', 'state' => 'anything']);

        $this->createController($request, configValueWriter: $configValueWriter)->callback($request);

        $this->assertSame(['danger' => ['review.google_state_mismatch']], $this->flashes($request));
    }

    // Google sends the owner back with no code when they decline, which is a refusal and not an error to report as one
    public function testCallbackReportsARefusedAuthorization(): void
    {
        $request = $this->createRequest(['state' => 'expected-state']);
        $request->getSession()->set('social_google_oauth_state', 'expected-state');

        $this->createController($request)->callback($request);

        $this->assertSame(['danger' => ['review.google_authorization_refused']], $this->flashes($request));
    }

    // A listing that cannot be resolved leaves the message Google gave, the owner being the only one able to act on it
    public function testCallbackReportsAFailureToResolveTheListing(): void
    {
        $googleOAuthClient = $this->createStub(GoogleOAuthClient::class);
        $googleOAuthClient->method('exchangeCode')->willReturn('refresh-token');

        $resolver = $this->createStub(GoogleBusinessLocationResolver::class);
        $resolver->method('resolveFirst')->willThrowException(new \RuntimeException('The connected Google account manages no Business Profile account.'));

        $request = $this->createRequest(['code' => 'auth-code', 'state' => 'expected-state']);
        $request->getSession()->set('social_google_oauth_state', 'expected-state');

        $this->createController($request, $googleOAuthClient, null, $resolver)->callback($request);

        $this->assertSame(['danger' => ['The connected Google account manages no Business Profile account.']], $this->flashes($request));
    }

    // A network hiccup on a reconnection must leave the working connection exactly as it was, nothing having been written yet
    public function testCallbackLeavesTheConnectionUntouchedWhenTheExchangeFails(): void
    {
        $googleOAuthClient = $this->createStub(GoogleOAuthClient::class);
        $googleOAuthClient->method('exchangeCode')->willThrowException(new \RuntimeException('Google refused the code.'));

        $configValueWriter = $this->createMock(ConfigValueWriter::class);
        $configValueWriter->expects($this->never())->method('write');

        $request = $this->createRequest(['code' => 'auth-code', 'state' => 'expected-state']);
        $request->getSession()->set('social_google_oauth_state', 'expected-state');

        $response = $this->createController($request, $googleOAuthClient, $configValueWriter)->callback($request);

        $this->assertSame(['danger' => ['Google refused the code.']], $this->flashes($request));
        $this->assertSame('/management', $response->getTargetUrl());
    }

    // A fresh token left paired with the previous account's listing would answer 403 on every later synchronization, so the failed connection goes back to "not connected"
    public function testCallbackClearsTheTokenWhenTheListingCannotBeResolved(): void
    {
        $googleOAuthClient = $this->createStub(GoogleOAuthClient::class);
        $googleOAuthClient->method('exchangeCode')->willReturn('refresh-token');

        $resolver = $this->createStub(GoogleBusinessLocationResolver::class);
        $resolver->method('resolveFirst')->willThrowException(new \RuntimeException('The connected Google account manages no Business Profile account.'));

        $written = [];
        $configValueWriter = $this->createStub(ConfigValueWriter::class);
        $configValueWriter->method('write')->willReturnCallback(function (array $values) use (&$written): void {
            $written[] = $values;
        });

        $request = $this->createRequest(['code' => 'auth-code', 'state' => 'expected-state']);
        $request->getSession()->set('social_google_oauth_state', 'expected-state');

        $this->createController($request, $googleOAuthClient, $configValueWriter, $resolver)->callback($request);

        $this->assertSame([
            'social-google-oauth-refresh-token' => null,
            'social-google-business-account-id' => null,
            'social-google-business-location-id' => null,
        ], end($written));
    }
}
