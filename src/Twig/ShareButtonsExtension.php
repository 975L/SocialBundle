<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\SocialBundle\Twig;

use c975L\SocialBundle\Service\ShareButtonsServiceInterface;
use c975L\UiBundle\Entity\Block;
use c975L\UiBundle\Repository\BlockRepository;
use c975L\UiBundle\Service\IconServiceInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

// Migrated from c975L/ShareButtonsBundle's ShareButtons Twig extension. Renders direct links to each network's share URL - no internal redirect route: the previous ShareButtonsController only existed to proxy that redirect, which needed a double urlencode of the target URL to work around it being carried as an Apache path segment. Building the final URL once here, at render time, sidesteps both.
class ShareButtonsExtension extends AbstractExtension
{
    // Kind of the "share_buttons_settings" singleton Block (see ShareButtonsSettingsCrudController) - not shared as a public constant there either, matching SocialLinkExtension's own literal use of "social_links" for the same reason (no other consumer needs it)
    private const SETTINGS_KIND = 'share_buttons_settings';

    public function __construct(
        private readonly ShareButtonsServiceInterface $shareButtonsService,
        private readonly IconServiceInterface $iconService,
        private readonly RequestStack $requestStack,
        private readonly BlockRepository $blockRepository,
        private readonly TagAwareCacheInterface $cache,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('share_buttons', $this->renderShareButtons(...), [
                'needs_environment' => true,
                'is_safe' => ['html'],
            ]),
            new TwigFunction('share_buttons_default', $this->renderDefaultShareButtons(...), [
                'needs_environment' => true,
                'is_safe' => ['html'],
            ]),
        ];
    }

    // Uses the dashboard settings, falling back to share_buttons()' defaults until the singleton is saved
    // A null $id falls back to the singleton's anchor; an empty string does not, both bands sharing a page
    public function renderDefaultShareButtons(Environment $environment, ?string $id = null): string
    {
        $settings = $this->getSettingsBlock()?->getData() ?? [];

        return $this->renderShareButtons(
            $environment,
            // Every network unchecked falls back to the main set, same as a never-saved singleton - "??" alone wouldn't, an empty array being neither null nor a missing key. Hiding the band is what the "social-enable-share-buttons" config key is for.
            ($settings['networks'] ?? []) ?: 'main',
            // A singleton saved before shape and fill became two settings only carries the old "style", which is ignored: it renders at these defaults until an admin saves the form again
            $settings['shape'] ?? 'wide',
            $settings['fill'] ?? 'solid',
            id: null !== $id ? ($id ?: null) : ($settings['anchor'] ?? null),
        );
    }

    // Cross-request cache: this singleton Block is read on every page rendering share_buttons_default(), and barely ever changes - invalidated by SingletonBlockCacheInvalidationListener whenever it's saved/removed. Safe to cache the entity directly: only ->getData() is ever read from it (never rendered through render_block() like "social_links" is), so its medias/user are never touched
    private function getSettingsBlock(): ?Block
    {
        return $this->cache->get('singleton_block_' . self::SETTINGS_KIND, function (ItemInterface $item): ?Block {
            $item->expiresAfter(null);
            $item->tag(['singleton_block_' . self::SETTINGS_KIND]);

            return $this->blockRepository->findOneByKind(self::SETTINGS_KIND);
        });
    }

    /**
     * @param string[]|'main' $networks list of network keys, or 'main' for the default set
     */
    public function renderShareButtons(
        Environment $environment,
        array|string $networks = 'main',
        string $shape = 'wide',
        string $fill = 'solid',
        string $alignment = 'center',
        bool $displayIcon = true,
        bool $displayText = false,
        ?string $url = null,
        ?string $id = null,
    ): string {
        $networks = 'main' === $networks ? $this->shareButtonsService->getMainNetworks() : $networks;
        $pageUrl = $url ?? $this->requestStack->getCurrentRequest()?->getUri() ?? '';
        // Icons are the same brand SVGs used by UiBundle's IconPickerType (public/icons/*.svg) - reused by key here too, so dropping/overriding an icon in the app's own public/icons/ (which IconService reads last, taking precedence over bundle-provided ones) affects both features
        $icons = $this->iconService->getIcons();

        $buttons = [];
        foreach ($networks as $network) {
            $shareUrl = $this->shareButtonsService->getShareUrl($network, $pageUrl);
            if (null !== $shareUrl) {
                $buttons[] = [
                    'network' => $network,
                    'url' => $shareUrl,
                    'icon' => $icons[$network] ?? null,
                ];
            }
        }

        if ([] === $buttons) {
            return '';
        }

        return $environment->render('@c975LSocial/shareButtons/ShareButtons.html.twig', [
            'buttons' => $buttons,
            'shape' => $shape,
            'fill' => $fill,
            'alignment' => $alignment,
            'displayIcon' => $displayIcon,
            'displayText' => $displayText,
            'id' => $id,
        ]);
    }
}
