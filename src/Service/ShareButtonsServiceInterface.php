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
    /**
     * @return string[] the handful of network keys offered by default, a subset of getNetworks()
     */
    public function getMainNetworks(): array;

    /**
     * @return string[] every supported network key (e.g. "facebook", "bluesky", "linkedin")
     */
    public function getNetworks(): array;

    /**
     * @return string[] the button box/corner variants, matching the ".social-share--shape-{shape}" CSS classes
     */
    public function getShapes(): array;

    /**
     * @return string[] the button fill variants, matching the ".social-share--fill-{fill}" CSS classes
     */
    public function getFills(): array;

    /**
     * @param string $network one of getNetworks()
     * @param string $pageUrl the absolute url being shared, url-encoded into the result
     *
     * @return string|null null for a network this service doesn't support
     */
    public function getShareUrl(string $network, string $pageUrl): ?string;
}
