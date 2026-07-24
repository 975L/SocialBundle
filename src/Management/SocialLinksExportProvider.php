<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\SocialBundle\Management;

// Exports the site-wide "social_links" singleton Block (data.links - see SocialLinksCrudController), plugging it into ConfigBundle's "Sync" content export/import
class SocialLinksExportProvider extends SingletonBlockExportProvider
{
    public function getKind(): string
    {
        return SocialLinksImportProvider::KIND;
    }
}
