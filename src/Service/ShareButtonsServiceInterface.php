<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\SocialBundle\Service;

interface ShareButtonsServiceInterface
{
    public function getMainNetworks(): array;

    public function getNetworks(): array;

    public function getStyles(): array;

    public function getShareUrl(string $network, string $pageUrl): ?string;
}
