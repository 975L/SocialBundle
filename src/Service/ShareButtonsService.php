<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

// Networks supported by the "share_buttons()" Twig function
class ShareButtonsService implements ShareButtonsServiceInterface
{
    public const string COPY_NETWORK = 'link';

    private const array NETWORKS = [
        'facebook' => 'https://www.facebook.com/sharer/sharer.php?u=',
        'bluesky' => 'https://bsky.app/intent/compose?text=',
        'linkedin' => 'https://www.linkedin.com/shareArticle?url=',
        'pinterest' => 'https://pinterest.com/pin/create/button/?url=',
        'email' => 'mailto:?body=',
        'line' => 'https://social-plugins.line.me/lineit/share?url=',
        'reddit' => 'https://reddit.com/submit?url=',
        'telegram' => 'https://t.me/share/url?url=',
        'threads' => 'https://www.threads.net/intent/post?text=',
        'tumblr' => 'https://www.tumblr.com/share?u=',
        'whatsapp' => 'https://wa.me/?text=',
        // Share₂Fedi asks the reader for their own instance, a fediverse network having no single address to post to
        'mastodon' => 'https://s2f.kytta.dev/?text=',
        // No network: the button copies the page's address (see share-buttons-popup.js), the link itself being what a click without JavaScript opens
        self::COPY_NETWORK => '',
    ];

    private const array MAIN_NETWORKS = ['facebook', 'bluesky', 'linkedin', 'pinterest', 'email'];

    // Matches the ".social-share--shape-{shape}" variants styled in sass/_share-buttons.scss - the button's box and corners only, "wide" and "ellipse" rendering 65x50 against the other three's 50x50
    private const array SHAPES = ['wide', 'ellipse', 'square', 'rounded', 'circle'];

    // Matches the ".social-share--fill-{fill}" variants there too - what paints the box, independently of its shape. "solid" is the per-network brand color, and carries no rule of its own: it IS the base styling every button gets
    private const array FILLS = ['solid', 'transparent', 'outline', 'minimal'];

    public function getMainNetworks(): array
    {
        return self::MAIN_NETWORKS;
    }

    public function getNetworks(): array
    {
        return array_keys(self::NETWORKS);
    }

    public function getShapes(): array
    {
        return self::SHAPES;
    }

    public function getFills(): array
    {
        return self::FILLS;
    }

    public function getShareUrl(string $network, string $pageUrl): ?string
    {
        if (!isset(self::NETWORKS[$network])) {
            return null;
        }

        return self::COPY_NETWORK === $network ? $pageUrl : self::NETWORKS[$network] . urlencode($pageUrl);
    }
}
