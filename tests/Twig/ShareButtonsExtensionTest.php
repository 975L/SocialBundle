<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\SocialBundle\Tests\Twig;

use c975L\SocialBundle\Service\ShareButtonsServiceInterface;
use c975L\SocialBundle\Twig\ShareButtonsExtension;
use c975L\UiBundle\Entity\Block;
use c975L\UiBundle\Repository\BlockRepository;
use c975L\UiBundle\Service\IconServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Twig\Environment;
use Twig\Node\TextNode;
use Twig\TwigFunction;

class ShareButtonsExtensionTest extends TestCase
{
    // Real in-memory tag-aware pool (not a stub): storeSerialized stays at its default (true), same as the production filesystem-backed pool, so a test would catch a Block that doesn't actually survive a cache round-trip
    private function createCache(): TagAwareCacheInterface
    {
        return new TagAwareAdapter(new ArrayAdapter());
    }

    // Builds a ShareButtonsService double, recording every getShareUrl() call into $calls as [network, pageUrl]
    private function createShareButtonsService(
        array $mainNetworks,
        array &$calls,
        array $shareUrlsByNetwork = [],
    ): ShareButtonsServiceInterface {
        $service = $this->createStub(ShareButtonsServiceInterface::class);
        $service->method('getMainNetworks')->willReturn($mainNetworks);
        $service->method('getShareUrl')->willReturnCallback(
            function (string $network, string $pageUrl) use (&$calls, $shareUrlsByNetwork): ?string {
                $calls[] = [$network, $pageUrl];

                return $shareUrlsByNetwork[$network] ?? null;
            }
        );

        return $service;
    }

    // Builds a Twig Environment double, recording the template and context of its last render() call
    private function createEnvironment(?string &$template, ?array &$context, int &$renderCallCount): Environment
    {
        $environment = $this->createStub(Environment::class);
        $environment->method('render')->willReturnCallback(
            function (string $renderedTemplate, array $renderedContext) use (&$template, &$context, &$renderCallCount): string {
                $template = $renderedTemplate;
                $context = $renderedContext;
                ++$renderCallCount;

                return 'rendered-output';
            }
        );

        return $environment;
    }

    // Builds the extension, with a BlockRepository double answering "share_buttons_settings" with $settingsBlock
    private function createExtension(
        ShareButtonsServiceInterface $shareButtonsService,
        array $icons = [],
        ?Block $settingsBlock = null,
        ?Request $currentRequest = null,
        ?TagAwareCacheInterface $cache = null,
    ): ShareButtonsExtension {
        $iconService = $this->createStub(IconServiceInterface::class);
        $iconService->method('getIcons')->willReturn($icons);

        $requestStack = new RequestStack();
        if (null !== $currentRequest) {
            $requestStack->push($currentRequest);
        }

        $blockRepository = $this->createStub(BlockRepository::class);
        $blockRepository->method('findOneByKind')->willReturnCallback(
            static fn (string $kind) => 'share_buttons_settings' === $kind ? $settingsBlock : null
        );

        return new ShareButtonsExtension($shareButtonsService, $iconService, $requestStack, $blockRepository, $cache ?? $this->createCache());
    }

    // Both functions render raw HTML and must receive the Twig Environment to call render() themselves
    public function testGetFunctionsRegistersShareButtonsAndShareButtonsDefaultAsSafeHtmlNeedingEnvironment(): void
    {
        $calls = [];
        $extension = $this->createExtension($this->createShareButtonsService([], $calls));

        $functions = $extension->getFunctions();

        $this->assertCount(2, $functions);
        $this->assertSame(
            ['share_buttons', 'share_buttons_default'],
            array_map(static fn (TwigFunction $function) => $function->getName(), $functions)
        );
        foreach ($functions as $function) {
            $this->assertTrue($function->needsEnvironment());
            $this->assertSame(['html'], $function->getSafe(new TextNode('', 0)));
        }
    }

    // Default networks/style/alignment must resolve the main networks and build one button per network
    public function testRenderShareButtonsBuildsButtonsForMainNetworksUsingCurrentRequestUri(): void
    {
        $calls = [];
        $shareButtonsService = $this->createShareButtonsService(
            ['facebook', 'bluesky'],
            $calls,
            ['facebook' => 'https://share/facebook', 'bluesky' => 'https://share/bluesky'],
        );
        $request = Request::create('https://example.com/page');
        $extension = $this->createExtension(
            $shareButtonsService,
            ['facebook' => 'icons/facebook.svg'],
            null,
            $request,
        );
        $template = null;
        $context = null;
        $renderCallCount = 0;
        $environment = $this->createEnvironment($template, $context, $renderCallCount);

        $result = $extension->renderShareButtons($environment);

        $this->assertSame('rendered-output', $result);
        $this->assertSame(1, $renderCallCount);
        $this->assertSame('@c975LSocial/shareButtons/ShareButtons.html.twig', $template);
        $this->assertSame(
            [
                ['network' => 'facebook', 'url' => 'https://share/facebook', 'icon' => 'icons/facebook.svg'],
                ['network' => 'bluesky', 'url' => 'https://share/bluesky', 'icon' => null],
            ],
            $context['buttons']
        );
        $this->assertSame('distinct', $context['style']);
        $this->assertSame('center', $context['alignment']);
        $this->assertTrue($context['displayIcon']);
        $this->assertFalse($context['displayText']);
        // Every network is resolved against the current request's own uri, since no explicit url was given
        $this->assertSame([['facebook', 'https://example.com/page'], ['bluesky', 'https://example.com/page']], $calls);
    }

    // A network unknown to getShareUrl() (typo, removed network) must not produce a broken button
    public function testRenderShareButtonsSkipsNetworksWithoutResolvedShareUrl(): void
    {
        $calls = [];
        $shareButtonsService = $this->createShareButtonsService(
            [],
            $calls,
            ['facebook' => 'https://share/facebook'],
        );
        $extension = $this->createExtension($shareButtonsService, [], null, Request::create('https://example.com'));
        $template = null;
        $context = null;
        $renderCallCount = 0;
        $environment = $this->createEnvironment($template, $context, $renderCallCount);

        $extension->renderShareButtons($environment, ['facebook', 'myspace']);

        $this->assertCount(1, $context['buttons']);
        $this->assertSame('facebook', $context['buttons'][0]['network']);
    }

    // When no requested network resolves to a share url, the template must not even be rendered
    public function testRenderShareButtonsReturnsEmptyStringAndSkipsRenderWhenNoNetworkResolves(): void
    {
        $calls = [];
        $shareButtonsService = $this->createShareButtonsService([], $calls, []);
        $extension = $this->createExtension($shareButtonsService, [], null, Request::create('https://example.com'));
        $template = null;
        $context = null;
        $renderCallCount = 0;
        $environment = $this->createEnvironment($template, $context, $renderCallCount);

        $result = $extension->renderShareButtons($environment, ['myspace']);

        $this->assertSame('', $result);
        $this->assertSame(0, $renderCallCount);
    }

    // An explicit $url argument (e.g. sharing a specific article) takes precedence over the current request's uri
    public function testRenderShareButtonsUsesExplicitUrlParameterOverRequestStack(): void
    {
        $calls = [];
        $shareButtonsService = $this->createShareButtonsService(
            [],
            $calls,
            ['facebook' => 'https://share/facebook'],
        );
        $extension = $this->createExtension(
            $shareButtonsService,
            [],
            null,
            Request::create('https://example.com/current-page'),
        );
        $template = null;
        $context = null;
        $renderCallCount = 0;
        $environment = $this->createEnvironment($template, $context, $renderCallCount);

        $extension->renderShareButtons($environment, ['facebook'], 'distinct', 'center', true, false, 'https://example.com/explicit');

        $this->assertSame([['facebook', 'https://example.com/explicit']], $calls);
    }

    // Outside an HTTP context (e.g. a CLI-rendered template) and without an explicit url, an empty page url is used
    public function testRenderShareButtonsFallsBackToEmptyPageUrlWhenNoRequestAndNoUrlGiven(): void
    {
        $calls = [];
        $shareButtonsService = $this->createShareButtonsService(
            [],
            $calls,
            ['facebook' => 'https://share/facebook'],
        );
        $extension = $this->createExtension($shareButtonsService, []);
        $template = null;
        $context = null;
        $renderCallCount = 0;
        $environment = $this->createEnvironment($template, $context, $renderCallCount);

        $extension->renderShareButtons($environment, ['facebook']);

        $this->assertSame([['facebook', '']], $calls);
    }

    // The dashboard-configured singleton block overrides the default networks/style, once it has been saved
    public function testRenderDefaultShareButtonsUsesSettingsBlockNetworksAndStyleWhenPresent(): void
    {
        $calls = [];
        $shareButtonsService = $this->createShareButtonsService(
            ['facebook'],
            $calls,
            ['twitter' => 'https://share/twitter'],
        );
        $settingsBlock = (new Block())->setData(['networks' => ['twitter'], 'style' => 'circle']);
        $extension = $this->createExtension($shareButtonsService, [], $settingsBlock, Request::create('https://example.com'));
        $template = null;
        $context = null;
        $renderCallCount = 0;
        $environment = $this->createEnvironment($template, $context, $renderCallCount);

        $extension->renderDefaultShareButtons($environment);

        $this->assertSame('circle', $context['style']);
        $this->assertCount(1, $context['buttons']);
        $this->assertSame('twitter', $context['buttons'][0]['network']);
    }

    // Before the settings singleton has ever been saved, share_buttons_default() must behave like share_buttons()
    public function testRenderDefaultShareButtonsFallsBackToMainNetworksAndDistinctStyleWhenNoSettingsBlockExists(): void
    {
        $calls = [];
        $shareButtonsService = $this->createShareButtonsService(
            ['facebook'],
            $calls,
            ['facebook' => 'https://share/facebook'],
        );
        $extension = $this->createExtension($shareButtonsService, [], null, Request::create('https://example.com'));
        $template = null;
        $context = null;
        $renderCallCount = 0;
        $environment = $this->createEnvironment($template, $context, $renderCallCount);

        $extension->renderDefaultShareButtons($environment);

        $this->assertSame('distinct', $context['style']);
        $this->assertSame('facebook', $context['buttons'][0]['network']);
    }

    // renderDefaultShareButtons() reads the settings singleton on every call - only the first one in a request should hit the repository
    public function testRenderDefaultShareButtonsMemoizesTheSettingsBlockWithinTheSameCacheInstance(): void
    {
        $calls = [];
        $shareButtonsService = $this->createShareButtonsService(['facebook'], $calls, ['facebook' => 'https://share/facebook']);
        $settingsBlock = (new Block())->setKind('share_buttons_settings')->setData(['networks' => ['facebook'], 'style' => 'circle']);

        $blockRepository = $this->createMock(BlockRepository::class);
        $blockRepository->expects($this->once())->method('findOneByKind')->with('share_buttons_settings')->willReturn($settingsBlock);

        $iconService = $this->createStub(IconServiceInterface::class);
        $cache = $this->createCache();
        $extension = new ShareButtonsExtension($shareButtonsService, $iconService, new RequestStack(), $blockRepository, $cache);

        $template = null;
        $context = null;
        $renderCallCount = 0;
        $environment = $this->createEnvironment($template, $context, $renderCallCount);
        $extension->renderDefaultShareButtons($environment);
        $extension->renderDefaultShareButtons($environment);
    }

    // The whole point of a cross-request cache: a fresh ShareButtonsExtension instance (simulating a new request) sharing the same cache pool must not hit the repository again
    public function testRenderDefaultShareButtonsSurvivesAcrossInstancesSharingTheSameCachePool(): void
    {
        $calls = [];
        $shareButtonsService = $this->createShareButtonsService(['facebook'], $calls, ['twitter' => 'https://share/twitter']);
        $settingsBlock = (new Block())->setKind('share_buttons_settings')->setData(['networks' => ['twitter'], 'style' => 'circle']);

        $blockRepository = $this->createMock(BlockRepository::class);
        $blockRepository->expects($this->once())->method('findOneByKind')->with('share_buttons_settings')->willReturn($settingsBlock);
        $iconService = $this->createStub(IconServiceInterface::class);

        $cache = $this->createCache();
        $firstRequest = new ShareButtonsExtension($shareButtonsService, $iconService, new RequestStack(), $blockRepository, $cache);
        $template = null;
        $context = null;
        $renderCallCount = 0;
        $environment = $this->createEnvironment($template, $context, $renderCallCount);
        $firstRequest->renderDefaultShareButtons($environment);
        $this->assertSame('circle', $context['style']);

        $secondRequest = new ShareButtonsExtension($shareButtonsService, $iconService, new RequestStack(), $blockRepository, $cache);
        $secondRequest->renderDefaultShareButtons($environment);
        $this->assertSame('circle', $context['style']);
    }
}
