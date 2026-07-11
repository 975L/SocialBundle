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
    private const NETWORKS = [
        'facebook' => 'https://www.facebook.com/sharer/sharer.php?u=',
        'bluesky' => 'https://bsky.app/intent/compose?text=',
        'linkedin' => 'https://www.linkedin.com/shareArticle?url=',
        'pinterest' => 'https://pinterest.com/pin/create/button/?url=',
        'email' => 'mailto:?body=',
        'blogger' => 'https://www.blogger.com/start?successUrl=/blog-this.g?t&passive=true&u=',
        'buffer' => 'https://bufferapp.com/add?url=',
        'delicious' => 'https://delicious.com/save?v=5&noui&jump=close&url=',
        'evernote' => 'https://www.evernote.com/clip.action?url=',
        'line' => 'https://social-plugins.line.me/lineit/share?url=',
        'reddit' => 'https://reddit.com/submit?url=',
        'skype' => 'https://web.skype.com/share?url=',
        'stumbleupon' => 'https://www.stumbleupon.com/submit?url=',
        'telegram' => 'https://t.me/share/url?url=',
        'threads' => 'https://www.threads.net/intent/post?text=',
        'tumblr' => 'https://www.tumblr.com/share?u=',
        'vk' => 'https://vk.com/share.php?url=',
        'whatsapp' => 'https://web.whatsapp.com/send?text=',
        'wordpress' => 'https://wordpress.com/press-this.php?u=',
        'xing' => 'https://www.xing.com/spi/shares/new?url=',
    ];

    private const MAIN_NETWORKS = ['facebook', 'bluesky', 'linkedin', 'pinterest', 'email'];

    // Matches the ".social-share--{style}" variants styled in sass/_share-buttons.scss
    private const STYLES = ['distinct', 'ellipse', 'circle', 'square', 'rounded', 'outline', 'minimal'];

    public function getMainNetworks(): array
    {
        return self::MAIN_NETWORKS;
    }

    public function getNetworks(): array
    {
        return array_keys(self::NETWORKS);
    }

    public function getStyles(): array
    {
        return self::STYLES;
    }

    public function getShareUrl(string $network, string $pageUrl): ?string
    {
        if (!isset(self::NETWORKS[$network])) {
            return null;
        }

        return self::NETWORKS[$network] . urlencode($pageUrl);
    }
}
