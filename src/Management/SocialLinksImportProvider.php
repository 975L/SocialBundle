<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\SocialBundle\Management;

// Imports a "social_links" Sync export back onto the site-wide singleton Block (see SocialLinksCrudController) - mirrors SocialLinksExportProvider
class SocialLinksImportProvider extends SingletonBlockImportProvider
{
    public const KIND = 'social_links';

    protected function getKind(): string
    {
        return self::KIND;
    }
}
