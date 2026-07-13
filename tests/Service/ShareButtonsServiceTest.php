<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Service\ShareButtonsService;
use PHPUnit\Framework\TestCase;

class ShareButtonsServiceTest extends TestCase
{
    private function createService(): ShareButtonsService
    {
        return new ShareButtonsService();
    }

    // The template highlights these networks first, so their order is part of the public contract
    public function testGetMainNetworksReturnsFacebookBlueskyLinkedinPinterestEmailInOrder(): void
    {
        $service = $this->createService();

        $this->assertSame(
            ['facebook', 'bluesky', 'linkedin', 'pinterest', 'email'],
            $service->getMainNetworks()
        );
    }

    // The "more networks" dropdown relies on every main network also being listed here
    public function testGetNetworksIncludesAllMainNetworksPlusAdditionalOnes(): void
    {
        $service = $this->createService();

        $networks = $service->getNetworks();

        foreach ($service->getMainNetworks() as $mainNetwork) {
            $this->assertContains($mainNetwork, $networks);
        }
        $this->assertContains('telegram', $networks);
        $this->assertContains('whatsapp', $networks);
        $this->assertCount(20, $networks);
    }

    // Styles must match the ".social-share--{style}" variants defined in sass/_share-buttons.scss
    public function testGetStylesReturnsSupportedCssVariants(): void
    {
        $service = $this->createService();

        $this->assertSame(
            ['distinct', 'ellipse', 'circle', 'square', 'rounded', 'outline', 'minimal'],
            $service->getStyles()
        );
    }

    // A share link must embed the url-encoded page url after the network's own base url
    public function testGetShareUrlBuildsFacebookLinkWithEncodedPageUrl(): void
    {
        $service = $this->createService();

        $this->assertSame(
            'https://www.facebook.com/sharer/sharer.php?u=' . urlencode('https://example.com/some page?a=1&b=2'),
            $service->getShareUrl('facebook', 'https://example.com/some page?a=1&b=2')
        );
    }

    // The "email" network is the only one using the mailto scheme instead of an https share endpoint
    public function testGetShareUrlBuildsMailtoLinkForEmailNetwork(): void
    {
        $service = $this->createService();

        $this->assertSame(
            'mailto:?body=' . urlencode('https://example.com/article'),
            $service->getShareUrl('email', 'https://example.com/article')
        );
    }

    // An unsupported network name (e.g. a typo, or a removed network) must not produce a broken link
    public function testGetShareUrlReturnsNullForUnknownNetwork(): void
    {
        $service = $this->createService();

        $this->assertNull($service->getShareUrl('myspace', 'https://example.com'));
    }

    // An empty page url is still encoded consistently, rather than triggering a special case
    public function testGetShareUrlHandlesEmptyPageUrl(): void
    {
        $service = $this->createService();

        $this->assertSame(
            'https://www.linkedin.com/shareArticle?url=',
            $service->getShareUrl('linkedin', '')
        );
    }
}
