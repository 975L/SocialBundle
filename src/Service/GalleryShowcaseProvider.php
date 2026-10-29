<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\SocialBundle\Form\Block\SocialLinksType;
use c975L\UiBundle\Contract\GalleryShowcaseProviderInterface;
use c975L\UiBundle\Service\IconServiceInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

// Shows every visual style of "social_links_display"/"share_buttons_display", and the "google_reviews" carousel, in a block gallery/showcase (see UiBundle's GalleryShowcaseRegistry, consumed by the public block showcase). Neither fits BlockFixtureProviderInterface: both always render a site-wide singleton regardless of their own data (see SocialLinksDisplay.html.twig/ShareButtonsDisplay.html.twig) - rendered here instead, directly against the same underlying components/markup, with a fixed sample set of networks (Laurent's own picks, one set per showcase) instead of the real singleton/current page URL that real render depends on.
class GalleryShowcaseProvider implements GalleryShowcaseProviderInterface
{
    private const array SOCIAL_LINKS_NETWORKS = ['facebook', 'bluesky', 'linkedin'];
    private const array SHARE_BUTTONS_NETWORKS = ['facebook', 'bluesky', 'pinterest'];
    // Author and rating of each sample review, their texts translated (label.gallery_showcase_google_reviews_sample_*)
    private const array GOOGLE_REVIEWS_SAMPLE = [['Marie L.', 5], ['Thomas B.', 5], ['Sophie R.', 4], ['Karim D.', 5]];

    public function __construct(
        private readonly Environment $twig,
        private readonly ShareButtonsServiceInterface $shareButtonsService,
        private readonly IconServiceInterface $iconService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getShowcases(): array
    {
        return [
            // Stands in for "social_links_display" - same feature, just previewed here without the singleton lookup that block kind's real render depends on. Its own regular (empty) preview card is suppressed by the gallery once "kind" is set here, so it only shows up once.
            $this->translator->trans('label.gallery_showcase_social_links', [], 'social') => [
                'description' => $this->translator->trans('label.gallery_showcase_social_links_description', [], 'social'),
                'kind' => 'social_links_display',
                'variants' => $this->socialLinksVariants(),
            ],
            // Stands in for "share_buttons_display" - same feature, just previewed here with a fixed sample set of networks instead of the real "share_buttons_settings" singleton (and the current page's own URL) that block's real render depends on. Its own regular (data-less) preview card is suppressed by the gallery once "kind" is set here, so it only shows up once. No "wide" needed: _share-buttons.scss's visual rules (colors/sizes/shapes) aren't gated by the 768px breakpoint, only the base visibility is - same as _social.scss, so the gallery's own ".social-share { display:flex !important }" override (see the previewIframe macro) is enough on its own, in a normal-width card.
            $this->translator->trans('label.gallery_showcase_share_buttons', [], 'social') => [
                'description' => $this->translator->trans('label.gallery_showcase_share_buttons_description', [], 'social'),
                'kind' => 'share_buttons_display',
                'variants' => $this->shareButtonsVariants(),
            ],
            // Stands in for "google_reviews" - which reviews it shows is the moderation list's say, never the block's own data, so a plain fixture would render empty on a site holding none. Sample reviews fed to the same partial the block draws through instead
            $this->translator->trans('label.gallery_showcase_google_reviews', [], 'social') => [
                'description' => $this->translator->trans('label.gallery_showcase_google_reviews_description', [], 'social'),
                'kind' => 'google_reviews',
                'variants' => [
                    $this->translator->trans('block.google_reviews', [], 'social') => $this->googleReviewsVariant(),
                ],
            ],
        ];
    }

    // Four reviews, so the row shows its arrows and dots on a computer too. Not verified, as the Google ones never are
    private function googleReviewsVariant(): string
    {
        $items = [];
        foreach (self::GOOGLE_REVIEWS_SAMPLE as $index => [$author, $rating]) {
            $items[] = [
                'id' => $index + 1,
                'title' => $author,
                'content' => $this->translator->trans('label.gallery_showcase_google_reviews_sample_' . ($index + 1), [], 'social'),
                'url' => null,
                'imageUrl' => null,
                'rating' => $rating,
                'publishedAt' => new \DateTimeImmutable(sprintf('-%d days', 7 * ($index + 1))),
                'replyComment' => null,
                'repliedAt' => null,
                'source' => GoogleBusinessProfileSource::NAME,
                'verified' => false,
            ];
        }

        return $this->twig->render('@c975LSocial/reviews/GoogleReviews.html.twig', [
            'id' => 'gallery-google-reviews',
            'anchorId' => '',
            'title' => '',
            'duration' => 0,
            'reviews' => [
                'items' => $items,
                'count' => count($items),
                'average' => round(array_sum(array_column(self::GOOGLE_REVIEWS_SAMPLE, 1)) / count($items), 1),
                'listingUrl' => '#',
            ],
        ]);
    }

    // One per SocialLinksType::ICON_STYLES choice - rendered via the same "blocks/SocialLinks.html.twig" adapter a real block uses, just fed sample links directly instead of through render_block()
    private function socialLinksVariants(): array
    {
        $links = array_map(
            static fn (string $network): array => ['network' => $network, 'url' => '#', 'customLabel' => '', 'customIcon' => ''],
            self::SOCIAL_LINKS_NETWORKS
        );

        $variants = [];
        foreach (SocialLinksType::ICON_STYLES as $style) {
            $variants[ucfirst($style)] = $this->twig->render('@c975LSocial/blocks/SocialLinks.html.twig', [
                'links' => $links,
                'iconStyle' => $style,
                'displayLabel' => true,
            ]);
        }

        return $variants;
    }

    // One card per shape, then one per fill other than "solid" - not the 20 cells of the two axes crossed, which would bury what each setting actually does under repetition. Each axis is shown against the other's default, "solid" being what the shape cards already stand on. displayText stays at its real default (false): every button is a fixed 50-65px icon badge (see _share-buttons.scss), too narrow to fit a network name next to the icon without it overlapping/getting clipped - real integrators leave it off for the same reason, relying on the icon/brand color to identify the network and the button's own aria-label (already rendered regardless of displayText) for accessibility.
    private function shareButtonsVariants(): array
    {
        $icons = $this->iconService->getIcons();
        $buttons = array_map(
            static fn (string $network): array => ['network' => $network, 'url' => '#', 'icon' => $icons[$network] ?? null],
            self::SHARE_BUTTONS_NETWORKS
        );

        $variants = [];
        foreach ($this->shareButtonsService->getShapes() as $shape) {
            $variants[ucfirst($shape)] = $this->renderShareButtons($buttons, $shape, 'solid');
        }

        // "circle" rather than the default shape: the three carry a border or no background at all, which a 65x50 box shows off less clearly than a round one
        foreach (array_diff($this->shareButtonsService->getFills(), ['solid']) as $fill) {
            $variants[ucfirst($fill)] = $this->renderShareButtons($buttons, 'circle', $fill);
        }

        return $variants;
    }

    // "transparent" is wrapped in the stand-in band of sass/_share-buttons.scss: it carries no color of its own, so on the gallery's own page background its card would show an empty row. The class only declares custom properties, which inherit, so wrapping paints the band inside just as putting it on the band would - and does it without ShareButtons.html.twig, a real front-end template, growing a preview-only parameter.
    private function renderShareButtons(array $buttons, string $shape, string $fill): string
    {
        $rendered = $this->twig->render('@c975LSocial/shareButtons/ShareButtons.html.twig', [
            'buttons' => $buttons,
            'shape' => $shape,
            'fill' => $fill,
            'alignment' => 'center',
            'displayIcon' => true,
            'displayText' => false,
        ]);

        return 'transparent' === $fill
            ? '<div class="social-share--preview-backdrop">' . $rendered . '</div>'
            : $rendered;
    }
}
