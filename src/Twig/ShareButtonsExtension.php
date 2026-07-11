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
use c975L\UiBundle\Repository\BlockRepository;
use c975L\UiBundle\Service\IconServiceInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

// Migrated from c975L/ShareButtonsBundle's ShareButtons Twig extension. Renders direct links to each
// network's share URL - no internal redirect route: the previous ShareButtonsController only existed to
// proxy that redirect, which needed a double urlencode of the target URL to work around it being carried
// as an Apache path segment. Building the final URL once here, at render time, sidesteps both.
class ShareButtonsExtension extends AbstractExtension
{
    // Kind of the "share_buttons_settings" singleton Block (see ShareButtonsSettingsCrudController) -
    // not shared as a public constant there either, matching SocialLinkExtension's own literal use of
    // "social_links" for the same reason (no other consumer needs it)
    private const SETTINGS_KIND = 'share_buttons_settings';

    public function __construct(
        private readonly ShareButtonsServiceInterface $shareButtonsService,
        private readonly IconServiceInterface $iconService,
        private readonly RequestStack $requestStack,
        private readonly BlockRepository $blockRepository,
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

    // Renders with the networks/style configured in the dashboard (see ShareButtonsSettingsCrudController),
    // falling back to the same defaults as share_buttons() as long as the settings singleton hasn't been
    // saved yet. Meant to be called unconditionally from the site layout, gated by the
    // "social-enable-share-buttons" config key - not by the settings themselves being present.
    public function renderDefaultShareButtons(Environment $environment): string
    {
        $settings = $this->blockRepository->findOneByKind(self::SETTINGS_KIND)?->getData() ?? [];

        return $this->renderShareButtons(
            $environment,
            $settings['networks'] ?? 'main',
            $settings['style'] ?? 'distinct',
        );
    }

    /**
     * @param string[]|'main' $networks list of network keys, or 'main' for the default set
     */
    public function renderShareButtons(
        Environment $environment,
        array|string $networks = 'main',
        string $style = 'distinct',
        string $alignment = 'center',
        bool $displayIcon = true,
        bool $displayText = false,
        ?string $url = null,
    ): string {
        $networks = 'main' === $networks ? $this->shareButtonsService->getMainNetworks() : $networks;
        $pageUrl = $url ?? $this->requestStack->getCurrentRequest()?->getUri() ?? '';
        // Icons are the same brand SVGs used by UiBundle's IconPickerType (public/icons/*.svg) - reused
        // by key here too, so dropping/overriding an icon in the app's own public/icons/ (which
        // IconService reads last, taking precedence over bundle-provided ones) affects both features
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
            'style' => $style,
            'alignment' => $alignment,
            'displayIcon' => $displayIcon,
            'displayText' => $displayText,
        ]);
    }
}
