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
use c975L\SocialBundle\Service\YoutubeClient;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Twig\Environment;

// What each tile of "Connexions" is handed: the screen only reads the networks' clients, it never writes
class SocialConnectionsControllerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $rendered = [];

    // Same seam as SocialCalendarControllerTest: AbstractController resolves security and Twig through its container
    /** @param array<string, string> $configs */
    private function networks(BlueskyOAuthClient $bluesky, string $host = 'site.example', array $configs = []): array
    {
        $configs += [
            'site-role-editor' => 'ROLE_EDITOR',
            'social-bluesky-handle' => ' me.bsky.social ',
            'social-meta-page-id' => '123',
            'social-meta-instagram-id' => '',
            'social-youtube-channel-id' => 'UC123',
            'social-youtube-channel-title' => 'Papa Câlin',
        ];
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key) => $configs[$key] ?? null);

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
        $youtube = $this->createStub(YoutubeClient::class);
        $youtube->method('isConfigured')->willReturn(true);
        $youtube->method('isConnected')->willReturn(true);

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

        $controller = new SocialConnectionsController($configService, $bluesky, $meta, $google, $linkedIn, $youtube);
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

        $this->assertSame(['bluesky', 'meta', 'linkedin', 'youtube', 'google'], array_keys($networks));
        $this->assertSame(['social_bluesky_oauth_connect', 'social_meta_oauth_connect', 'social_linkedin_oauth_connect', 'social_youtube_oauth_connect', 'social_google_oauth_connect'], array_column($networks, 'route'));
        $this->assertSame([['icon' => 'fab fa-bluesky', 'name' => 'me.bsky.social', 'url' => 'https://bsky.app/profile/me.bsky.social']], $networks['bluesky']['accounts']);
        $this->assertSame([['icon' => 'fab fa-facebook', 'name' => '123', 'url' => 'https://www.facebook.com/123']], $networks['meta']['accounts']);
        $this->assertTrue($networks['meta']['configured']);
        $this->assertFalse($networks['meta']['connected']);
        $this->assertFalse($networks['meta']['instagram']);
        $this->assertTrue($networks['linkedin']['connected']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $networks['linkedin']['expires_at']);
        $this->assertFalse($networks['google']['configured']);
        $this->assertSame([], $networks['google']['accounts']);
    }

    // The channel under its title, linked, so the owner sees which of their channels consented
    public function testTheYoutubeTileLinksTheChannel(): void
    {
        $networks = $this->networks($this->bluesky());

        $this->assertTrue($networks['youtube']['connected']);
        $this->assertSame([['icon' => 'fab fa-youtube', 'name' => 'Papa Câlin', 'url' => 'https://www.youtube.com/channel/UC123']], $networks['youtube']['accounts']);
    }

    // The Page under its name and the Instagram account under its username, each linked - one connected before their names were stored left unnamed and unlinked
    public function testTheMetaTileLinksThePageAndTheInstagramAccount(): void
    {
        $named = $this->networks($this->bluesky(), configs: ['social-meta-page-name' => 'Papa Câlin', 'social-meta-instagram-id' => '77', 'social-meta-instagram-username' => 'papacalin']);
        $this->assertSame([
            ['icon' => 'fab fa-facebook', 'name' => 'Papa Câlin', 'url' => 'https://www.facebook.com/123'],
            ['icon' => 'fab fa-instagram', 'name' => '@papacalin', 'url' => 'https://www.instagram.com/papacalin/'],
        ], $named['meta']['accounts']);

        $unnamed = $this->networks($this->bluesky(), configs: ['social-meta-instagram-id' => '77']);
        $instagram = $unnamed['meta']['accounts'][1];
        $this->assertInstanceOf(TranslatableMessage::class, $instagram['name']);
        $this->assertSame(['label.social_meta_page_instagram_linked', null], [$instagram['name']->getMessage(), $instagram['url']]);
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
