<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Twig;

use c975L\SocialBundle\Controller\Management\ShareButtonsSettingsCrudController;
use c975L\SocialBundle\Service\ShareButtonsService;
use c975L\SocialBundle\Service\ShareButtonsServiceInterface;
use c975L\SocialBundle\Twig\ShareButtonsExtension;
use c975L\UiBundle\Entity\Block;
use c975L\UiBundle\Repository\BlockRepository;
use c975L\UiBundle\Service\IconServiceInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
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
        // Shapes come from the real service rather than being restated here: a pure lookup the extension only reads through, and a copy of it would let this suite keep passing against a list the bundle no longer has
        $real = new ShareButtonsService();

        $service = $this->createStub(ShareButtonsServiceInterface::class);
        $service->method('getMainNetworks')->willReturn($mainNetworks);
        $service->method('getShapes')->willReturn($real->getShapes());
        $service->method('getShareUrl')->willReturnCallback(
            function (string $network, string $pageUrl) use (&$calls, $shareUrlsByNetwork): ?string {
                $calls[] = [$network, $pageUrl];

                return $shareUrlsByNetwork[$network] ?? null;
            }
        );

        return $service;
    }

    // Builds an AdminUrlGenerator double, fluent all the way through and answering a fixed url - what a test asserts of it is the screen it is asked for, never the url EasyAdmin makes of it
    private function createAdminUrlGenerator(): AdminUrlGeneratorInterface
    {
        $adminUrlGenerator = $this->createStub(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->method('unsetAll')->willReturnSelf();
        $adminUrlGenerator->method('setController')->willReturnSelf();
        $adminUrlGenerator->method('setAction')->willReturnSelf();
        $adminUrlGenerator->method('setEntityId')->willReturnSelf();
        $adminUrlGenerator->method('generateUrl')->willReturn('/management/?crudAction=edit');

        return $adminUrlGenerator;
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
        ?AdminUrlGeneratorInterface $adminUrlGenerator = null,
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

        return new ShareButtonsExtension($shareButtonsService, $iconService, $requestStack, $blockRepository, $cache ?? $this->createCache(), $adminUrlGenerator ?? $this->createAdminUrlGenerator());
    }

    // Both rendering functions render raw HTML and must receive the Twig Environment to call render() themselves - "share_buttons_edit_url" returns an url, escaped like any other attribute value
    public function testGetFunctionsRegistersShareButtonsAndShareButtonsDefaultAsSafeHtmlNeedingEnvironment(): void
    {
        $calls = [];
        $extension = $this->createExtension($this->createShareButtonsService([], $calls));

        $functions = $extension->getFunctions();

        $this->assertSame(
            ['share_buttons', 'share_buttons_default', 'share_buttons_edit_url'],
            array_map(static fn (TwigFunction $function) => $function->getName(), $functions)
        );
        foreach (\array_slice($functions, 0, 2) as $function) {
            $this->assertTrue($function->needsEnvironment());
            $this->assertSame(['html'], $function->getSafe(new TextNode('', 0)));
        }
        $this->assertFalse($functions[2]->needsEnvironment());
        $this->assertSame([], $functions[2]->getSafe(new TextNode('', 0)));
    }

    // The hover button offered to an editor on the public band (see shareButtons/default.html.twig) opens the singleton holding its networks and its style, not the page it was hovered on
    public function testGetEditUrlPointsAtTheSavedSettingsSingleton(): void
    {
        $settingsBlock = (new Block())->setKind('share_buttons_settings');
        (new \ReflectionProperty(Block::class, 'id'))->setValue($settingsBlock, 42);

        $adminUrlGenerator = $this->createMock(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->method('unsetAll')->willReturnSelf();
        $adminUrlGenerator->expects($this->once())->method('setController')->with(ShareButtonsSettingsCrudController::class)->willReturnSelf();
        $adminUrlGenerator->expects($this->once())->method('setAction')->with(Action::EDIT)->willReturnSelf();
        $adminUrlGenerator->expects($this->once())->method('setEntityId')->with(42)->willReturnSelf();
        $adminUrlGenerator->method('generateUrl')->willReturn('/management/?crudAction=edit&entityId=42');

        $calls = [];
        $extension = $this->createExtension($this->createShareButtonsService([], $calls), [], $settingsBlock, null, null, $adminUrlGenerator);

        $this->assertSame('/management/?crudAction=edit&entityId=42', $extension->getEditUrl());
    }

    // Nothing to edit until the singleton is saved, and the band is rendered from its defaults all the same: the screen creating it is the one an editor is sent to, rather than an edit form for a row with no id
    public function testGetEditUrlPointsAtTheCreationScreenWhenNoSettingsAreSavedYet(): void
    {
        $adminUrlGenerator = $this->createMock(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->method('unsetAll')->willReturnSelf();
        $adminUrlGenerator->method('setController')->willReturnSelf();
        $adminUrlGenerator->expects($this->once())->method('setAction')->with(Action::NEW)->willReturnSelf();
        $adminUrlGenerator->expects($this->never())->method('setEntityId');
        $adminUrlGenerator->method('generateUrl')->willReturn('/management/?crudAction=new');

        $calls = [];
        $extension = $this->createExtension($this->createShareButtonsService([], $calls), [], null, null, null, $adminUrlGenerator);

        $this->assertSame('/management/?crudAction=new', $extension->getEditUrl());
    }

    // EasyAdmin reads the dashboard an admin URL is mounted under from a cache map only written when the route collection is regenerated, so it can be missing on a perfectly working site (a wiped cache pool, fresh compiled routes) - the band's hover button then simply isn't offered, where a thrown error would take the whole public page down for the editor
    public function testGetEditUrlReturnsNullWhenTheAdminUrlCannotBeGenerated(): void
    {
        $adminUrlGenerator = $this->createStub(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->method('unsetAll')->willReturnSelf();
        $adminUrlGenerator->method('setController')->willReturnSelf();
        $adminUrlGenerator->method('setAction')->willReturnSelf();
        $adminUrlGenerator->method('generateUrl')->willThrowException(new \TypeError('setDashboard(): Argument #1 must be of type string, null given'));

        $calls = [];
        $extension = $this->createExtension($this->createShareButtonsService([], $calls), [], null, null, null, $adminUrlGenerator);

        $this->assertNull($extension->getEditUrl());
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
        $this->assertSame('wide', $context['shape']);
        $this->assertSame('solid', $context['fill']);
        $this->assertSame('center', $context['alignment']);
        $this->assertTrue($context['displayIcon']);
        $this->assertFalse($context['displayText']);
        // Off for a hand-written share_buttons() call: the invitation line is what the dashboard-driven band opts into, not what every explicitly placed row grows on its own
        $this->assertFalse($context['displayIntro']);
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

        $extension->renderShareButtons($environment, ['facebook'], 'wide', 'solid', 'center', true, false, 'https://example.com/explicit');

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

    // A saved shape without a fill next to it must keep the fill at its own default rather than dragging the shape's down with it
    public function testRenderDefaultShareButtonsUsesSettingsBlockNetworksAndShapeWhenPresent(): void
    {
        $calls = [];
        $shareButtonsService = $this->createShareButtonsService(
            ['facebook'],
            $calls,
            ['telegram' => 'https://share/telegram'],
        );
        $settingsBlock = (new Block())->setData(['networks' => ['telegram'], 'shape' => 'circle']);
        $extension = $this->createExtension($shareButtonsService, [], $settingsBlock, Request::create('https://example.com'));
        $template = null;
        $context = null;
        $renderCallCount = 0;
        $environment = $this->createEnvironment($template, $context, $renderCallCount);

        $extension->renderDefaultShareButtons($environment);

        $this->assertSame('circle', $context['shape']);
        $this->assertSame('solid', $context['fill']);
        $this->assertCount(1, $context['buttons']);
        $this->assertSame('telegram', $context['buttons'][0]['network']);
    }

    // Both settings saved must both be read - the pair is what the dashboard writes on every save
    public function testRenderDefaultShareButtonsUsesBothSettingsBlockShapeAndFillWhenPresent(): void
    {
        $calls = [];
        $shareButtonsService = $this->createShareButtonsService(
            ['facebook'],
            $calls,
            ['facebook' => 'https://share/facebook'],
        );
        $settingsBlock = (new Block())->setData(['networks' => ['facebook'], 'shape' => 'rounded', 'fill' => 'minimal']);
        $extension = $this->createExtension($shareButtonsService, [], $settingsBlock, Request::create('https://example.com'));
        $template = null;
        $context = null;
        $renderCallCount = 0;
        $environment = $this->createEnvironment($template, $context, $renderCallCount);

        $extension->renderDefaultShareButtons($environment);

        $this->assertSame('rounded', $context['shape']);
        $this->assertSame('minimal', $context['fill']);
    }

    // Before the settings singleton has ever been saved, share_buttons_default() must behave like share_buttons()
    public function testRenderDefaultShareButtonsFallsBackToMainNetworksAndDefaultPairWhenNoSettingsBlockExists(): void
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

        $this->assertSame('wide', $context['shape']);
        $this->assertSame('solid', $context['fill']);
        $this->assertSame('facebook', $context['buttons'][0]['network']);
    }

    // The invitation line above the buttons is on by default - including on a singleton saved before the setting existed, which carries no key at all - and only off once an admin unchecks it
    public function testRenderDefaultShareButtonsShowsTheIntroUnlessItWasTurnedOff(): void
    {
        foreach (['never saved' => true, 'legacy' => true, 'on' => true, 'off' => false] as $case => $expected) {
            $calls = [];
            $shareButtonsService = $this->createShareButtonsService(
                ['facebook'],
                $calls,
                ['facebook' => 'https://share/facebook'],
            );
            $data = match ($case) {
                'legacy' => ['networks' => ['facebook']],
                'on' => ['networks' => ['facebook'], 'displayIntro' => true],
                'off' => ['networks' => ['facebook'], 'displayIntro' => false],
                default => null,
            };
            $extension = $this->createExtension(
                $shareButtonsService,
                [],
                null !== $data ? (new Block())->setData($data) : null,
                Request::create('https://example.com'),
            );
            $template = null;
            $context = null;
            $renderCallCount = 0;
            $environment = $this->createEnvironment($template, $context, $renderCallCount);

            $extension->renderDefaultShareButtons($environment);

            $this->assertSame($expected, $context['displayIntro'], sprintf('case "%s"', $case));
        }
    }

    // renderDefaultShareButtons() reads the settings singleton on every call - only the first one in a request should hit the repository
    public function testRenderDefaultShareButtonsMemoizesTheSettingsBlockWithinTheSameCacheInstance(): void
    {
        $calls = [];
        $shareButtonsService = $this->createShareButtonsService(['facebook'], $calls, ['facebook' => 'https://share/facebook']);
        $settingsBlock = (new Block())->setKind('share_buttons_settings')->setData(['networks' => ['facebook'], 'shape' => 'circle']);

        $blockRepository = $this->createMock(BlockRepository::class);
        $blockRepository->expects($this->once())->method('findOneByKind')->with('share_buttons_settings')->willReturn($settingsBlock);

        $iconService = $this->createStub(IconServiceInterface::class);
        $cache = $this->createCache();
        $extension = new ShareButtonsExtension($shareButtonsService, $iconService, new RequestStack(), $blockRepository, $cache, $this->createAdminUrlGenerator());

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
        $shareButtonsService = $this->createShareButtonsService(['facebook'], $calls, ['telegram' => 'https://share/telegram']);
        $settingsBlock = (new Block())->setKind('share_buttons_settings')->setData(['networks' => ['telegram'], 'shape' => 'circle']);

        $blockRepository = $this->createMock(BlockRepository::class);
        $blockRepository->expects($this->once())->method('findOneByKind')->with('share_buttons_settings')->willReturn($settingsBlock);
        $iconService = $this->createStub(IconServiceInterface::class);

        $cache = $this->createCache();
        $firstRequest = new ShareButtonsExtension($shareButtonsService, $iconService, new RequestStack(), $blockRepository, $cache, $this->createAdminUrlGenerator());
        $template = null;
        $context = null;
        $renderCallCount = 0;
        $environment = $this->createEnvironment($template, $context, $renderCallCount);
        $firstRequest->renderDefaultShareButtons($environment);
        $this->assertSame('circle', $context['shape']);
        $this->assertSame('solid', $context['fill']);

        $secondRequest = new ShareButtonsExtension($shareButtonsService, $iconService, new RequestStack(), $blockRepository, $cache, $this->createAdminUrlGenerator());
        $secondRequest->renderDefaultShareButtons($environment);
        $this->assertSame('circle', $context['shape']);
        $this->assertSame('solid', $context['fill']);
    }

    // The "share_buttons_display" block's own anchor travels down to the template, which turns it into the band's id so a menu entry can link to it (see blocks/ShareButtonsDisplay.html.twig)
    public function testRenderDefaultShareButtonsForwardsTheGivenIdToTheTemplate(): void
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

        $extension->renderDefaultShareButtons($environment, 'partage-12');

        $this->assertSame('partage-12', $context['id']);
    }

    // The layout's own site-wide call passes no id and falls back to the singleton's anchor, so the band carries the same id on every page - what a navbar entry linking to it needs
    public function testRenderDefaultShareButtonsFallsBackToTheSettingsAnchorWhenNoIdIsGiven(): void
    {
        $calls = [];
        $shareButtonsService = $this->createShareButtonsService(
            ['facebook'],
            $calls,
            ['facebook' => 'https://share/facebook'],
        );
        $settingsBlock = (new Block())->setData(['networks' => ['facebook'], 'shape' => 'circle', 'anchor' => 'partage']);
        $extension = $this->createExtension($shareButtonsService, [], $settingsBlock, Request::create('https://example.com'));
        $template = null;
        $context = null;
        $renderCallCount = 0;
        $environment = $this->createEnvironment($template, $context, $renderCallCount);

        $extension->renderDefaultShareButtons($environment);

        $this->assertSame('partage', $context['id']);
    }

    // A block's own anchor wins over the site-wide one, so a "share_buttons_display" placed on a page keeps its own id
    public function testRenderDefaultShareButtonsPrefersTheGivenIdOverTheSettingsAnchor(): void
    {
        $calls = [];
        $shareButtonsService = $this->createShareButtonsService(
            ['facebook'],
            $calls,
            ['facebook' => 'https://share/facebook'],
        );
        $settingsBlock = (new Block())->setData(['networks' => ['facebook'], 'shape' => 'circle', 'anchor' => 'partage']);
        $extension = $this->createExtension($shareButtonsService, [], $settingsBlock, Request::create('https://example.com'));
        $template = null;
        $context = null;
        $renderCallCount = 0;
        $environment = $this->createEnvironment($template, $context, $renderCallCount);

        $extension->renderDefaultShareButtons($environment, 'partage-12');

        $this->assertSame('partage-12', $context['id']);
    }

    // A "share_buttons_display" block with no anchor passes an empty string, which must not fall back to the site-wide anchor: both bands can appear on the same page, and they would then carry the very same id
    public function testRenderDefaultShareButtonsDoesNotFallBackToTheSettingsAnchorWhenAnEmptyIdIsGiven(): void
    {
        $calls = [];
        $shareButtonsService = $this->createShareButtonsService(
            ['facebook'],
            $calls,
            ['facebook' => 'https://share/facebook'],
        );
        $settingsBlock = (new Block())->setData(['networks' => ['facebook'], 'shape' => 'circle', 'anchor' => 'partage']);
        $extension = $this->createExtension($shareButtonsService, [], $settingsBlock, Request::create('https://example.com'));
        $template = null;
        $context = null;
        $renderCallCount = 0;
        $environment = $this->createEnvironment($template, $context, $renderCallCount);

        $extension->renderDefaultShareButtons($environment, '');

        $this->assertNull($context['id']);
    }

    // Every network unchecked in the dashboard falls back to the main set, same as a never-saved singleton - otherwise the band silently disappears, taking its anchor with it
    public function testRenderDefaultShareButtonsFallsBackToMainNetworksWhenNoneIsSelected(): void
    {
        $calls = [];
        $shareButtonsService = $this->createShareButtonsService(
            ['facebook', 'bluesky'],
            $calls,
            ['facebook' => 'https://share/facebook', 'bluesky' => 'https://share/bluesky'],
        );
        $settingsBlock = (new Block())->setData(['networks' => [], 'shape' => 'circle']);
        $extension = $this->createExtension($shareButtonsService, [], $settingsBlock, Request::create('https://example.com'));
        $template = null;
        $context = null;
        $renderCallCount = 0;
        $environment = $this->createEnvironment($template, $context, $renderCallCount);

        $extension->renderDefaultShareButtons($environment);

        $this->assertSame(['facebook', 'bluesky'], array_column($context['buttons'], 'network'));
        $this->assertSame('circle', $context['shape']);
        $this->assertSame('solid', $context['fill']);
    }

    // Manual share_buttons() calls set no id unless one is passed, and the template only prints the attribute when it is set
    public function testRenderShareButtonsPassesANullIdByDefault(): void
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

        $extension->renderShareButtons($environment);

        $this->assertNull($context['id']);
    }
}
