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
use c975L\SocialBundle\Service\ConfigValueWriter;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatableInterface;

// The container a connection controller is tested with - access granted, routes named after themselves, "valid" the only CSRF token - the seams of its OAuth callback and the flashes it left
trait ControllerTestTrait
{
    private function createContainer(Request $request): ContainerInterface
    {
        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => 'valid' === $token->getValue());

        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route): string => '/' . $route);

        return $this->serviceContainer([
            'request_stack' => new RequestStack([$request]),
            'router' => $router,
            'security.csrf.token_manager' => $csrfTokenManager,
        ]);
    }

    // The container AbstractController resolves its services through, access granted unless another checker is given
    /** @param array<string, object> $services */
    private function serviceContainer(array $services): ContainerInterface
    {
        if (!isset($services['security.authorization_checker'])) {
            $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
            $authorizationChecker->method('isGranted')->willReturn(true);
            $services['security.authorization_checker'] = $authorizationChecker;
        }

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => isset($services[$id]));
        $container->method('get')->willReturnCallback(static fn (string $id) => $services[$id] ?? null);

        return $container;
    }

    // The configuration granting the editor's role, with the given values besides
    /** @param array<string, string|null> $values */
    private function configService(array $values = []): ConfigServiceInterface
    {
        $values += ['site-role-editor' => 'ROLE_EDITOR'];
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key) => $values[$key] ?? null);

        return $configService;
    }

    // A network's client holding its app's keys, its consent at the given url
    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return Stub&T
     */
    private function oauthClient(string $class, string $authorizationUrl): Stub
    {
        $client = $this->createStub($class);
        $client->method('isConfigured')->willReturn(true);
        $client->method('getAuthorizationUrl')->willReturn($authorizationUrl);

        return $client;
    }

    // The way back from the consent with a code, the session holding "expected-state" under the controller's key
    private function oauthCallbackRequest(string $sessionKey, string $state = 'expected-state'): Request
    {
        $request = new Request(['code' => 'auth-code', 'state' => $state]);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->getSession()->set($sessionKey, 'expected-state');

        return $request;
    }

    // A writer keeping what it is asked to write
    /** @param list<array<string, mixed>> $written */
    private function recordingWriter(array &$written): ConfigValueWriter
    {
        $configValueWriter = $this->createStub(ConfigValueWriter::class);
        $configValueWriter->method('write')->willReturnCallback(function (array $values) use (&$written): void {
            $written[] = $values;
        });

        return $configValueWriter;
    }

    // The flashes by type, a translatable one read as its key
    /** @return array<string, list<mixed>> */
    private function flashes(Request $request): array
    {
        $session = $request->getSession();
        \assert($session instanceof Session);

        return array_map(
            static fn (array $messages): array => array_map(static fn ($message) => $message instanceof TranslatableInterface ? $message->getMessage() : $message, $messages),
            $session->getFlashBag()->all()
        );
    }
}
