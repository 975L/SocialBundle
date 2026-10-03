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
use c975L\SocialBundle\Controller\Management\SocialConnectionsController;
use c975L\SocialBundle\Service\BlueskyOAuthClient;
use c975L\SocialBundle\Service\GoogleOAuthClient;
use c975L\SocialBundle\Service\LinkedInClient;
use c975L\SocialBundle\Service\MetaGraphClient;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Environment;

// What each tile of "Connexions" is handed: the screen only reads the networks' clients, it never writes
class SocialConnectionsControllerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $rendered = [];

    // Same seam as SocialCalendarControllerTest: AbstractController resolves security and Twig through its container
    private function networks(BlueskyOAuthClient $bluesky, string $host = 'site.example'): array
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key) => match ($key) {
            'site-role-editor' => 'ROLE_EDITOR',
            'social-bluesky-handle' => ' me.bsky.social ',
            'social-meta-page-id' => '123',
            'social-meta-instagram-id' => '',
            default => null,
        });

        $meta = $this->createStub(MetaGraphClient::class);
        $meta->method('isConfigured')->willReturn(true);
        $meta->method('pageToken')->willReturn(null);
        $google = $this->createStub(GoogleOAuthClient::class);
        $google->method('isConfigured')->willReturn(false);
        $linkedIn = $this->createStub(LinkedInClient::class);
        $linkedIn->method('isConfigured')->willReturn(true);
        $linkedIn->method('isConnected')->willReturn(true);
        $expiresAt = new \DateTimeImmutable('+30 days');
        $linkedIn->method('expiresAt')->willReturn($expiresAt);

        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $view, array $parameters): string {
            $this->rendered = $parameters;

            return '';
        });
        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn(true);
        $services = ['security.authorization_checker' => $authorizationChecker, 'twig' => $twig];
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => isset($services[$id]));
        $container->method('get')->willReturnCallback(static fn (string $id) => $services[$id] ?? null);

        $controller = new SocialConnectionsController($configService, $bluesky, $meta, $google, $linkedIn);
        $controller->setContainer($container);
        $controller->index(Request::create('https://' . $host . '/management/social-connections'));

        return array_column($this->rendered['networks'], null, 'name');
    }

    private function bluesky(bool $siteHost = true): BlueskyOAuthClient
    {
        $bluesky = $this->createStub(BlueskyOAuthClient::class);
        $bluesky->method('isSiteHost')->willReturn($siteHost);
        $bluesky->method('isConnected')->willReturn(true);
        $bluesky->method('siteUrl')->willReturn('https://site.example');

        return $bluesky;
    }

    // One tile per network, in the screen's order, each saying whether its app is set and whether it is connected
    public function testEachNetworkHasItsTile(): void
    {
        $networks = $this->networks($this->bluesky());

        $this->assertSame(['bluesky', 'meta', 'linkedin', 'google'], array_keys($networks));
        $this->assertSame(['social_bluesky_oauth_connect', 'social_meta_oauth_connect', 'social_linkedin_oauth_connect', 'social_google_oauth_connect'], array_column($networks, 'route'));
        $this->assertSame('me.bsky.social', $networks['bluesky']['account']);
        $this->assertTrue($networks['meta']['configured']);
        $this->assertFalse($networks['meta']['connected']);
        $this->assertFalse($networks['meta']['instagram']);
        $this->assertTrue($networks['linkedin']['connected']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $networks['linkedin']['expires_at']);
        $this->assertFalse($networks['google']['configured']);
        $this->assertNull($networks['google']['account']);
    }

    // On the site's own address there is nothing to warn about; anywhere else the tile names the address to connect from
    public function testTheBlueskyTileNamesTheSiteUrlOnAnotherHost(): void
    {
        $this->assertNull($this->networks($this->bluesky())['bluesky']['site_url']);
        $this->assertSame('https://site.example', $this->networks($this->bluesky(siteHost: false), 'preprod.example')['bluesky']['site_url']);
    }

    // No "site-url" at all is said by the connection itself once started: the screen still renders
    public function testAMissingSiteUrlStillRendersTheScreen(): void
    {
        $bluesky = $this->createStub(BlueskyOAuthClient::class);
        $bluesky->method('isSiteHost')->willThrowException(new \RuntimeException('"site-url" is not set.'));

        $this->assertNull($this->networks($bluesky)['bluesky']['site_url']);
    }
}
