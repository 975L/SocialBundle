<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Assets;

use PHPUnit\Framework\Attributes\Group;

// assets/js/share-buttons-popup.js, the one thing standing between a reader sharing a page and a reader leaving it
// The two halves both have to hold: the navigation is refused, and a window is opened in its place. Either one alone is a bug - a refused navigation with no window is a share button that does nothing at all, and a window with no refusal takes the reader off the page they were reading
#[Group('browser')]
class ShareButtonsPopupBehaviourTest extends JsCase
{
    private const string HREF = 'https://example.org/partager?url=https%3A%2F%2F975l.com';

    public function testTheReaderStaysOnThePageAndTheShareOpensBesideIt(): void
    {
        $shared = $this->button('link().click(); return { prevented, opened: window.__opened };');

        $this->assertTrue($shared['prevented'], 'The share link navigates, taking the reader off the page they were about to share.');
        $this->assertSame(self::HREF, $shared['opened']['url'], 'The window opened somewhere other than where the link points.');
        $this->assertSame('Share', $shared['opened']['name'], 'The window has no name, so a second share opens a second window rather than reusing the one already up.');
    }

    // Centred on the screen it opens over, and half of it: a window put at the top left of a wide screen is one the reader has to go looking for
    public function testTheWindowIsAskedForAtHalfTheScreenAndCentredOnIt(): void
    {
        $asked = $this->button('link().click(); return { features: window.__opened.features, width: screen.width, height: screen.height };');

        preg_match('/width=(?<width>[\d.]+), height=(?<height>[\d.]+), top=(?<top>[\d.]+), left=(?<left>[\d.]+)/', (string) $asked['features'], $window);

        $this->assertNotEmpty($window, 'The window is opened at whatever size and wherever the browser feels like.');
        $this->assertEqualsWithDelta($asked['width'] / 2, (float) $window['width'], 0.01, 'The share window does not take half the width of the screen it opens over.');
        $this->assertEqualsWithDelta($asked['height'] * 0.4, (float) $window['height'], 0.01, 'The share window does not take the height it was written for.');
        $this->assertEqualsWithDelta(($asked['width'] - (float) $window['width']) / 2, (float) $window['left'], 0.01, 'The window is not centred across the screen.');
        $this->assertEqualsWithDelta(($asked['height'] - (float) $window['height']) / 2, (float) $window['top'], 0.01, 'The window is not centred down the screen.');
    }

    // The chrome is asked off: a share window carrying an address bar and a menu is a second browser rather than a dialogue
    public function testTheWindowIsOpenedWithoutTheChromeOfABrowser(): void
    {
        $features = (string) $this->button('link().click(); return window.__opened.features;');

        foreach (['toolbar=no', 'location=no', 'menubar=no', 'status=no', 'resizable=no'] as $asked) {
            $this->assertStringContainsString($asked, $features, sprintf('The share window is opened with "%s", which makes it a browser window rather than a dialogue.', $asked));
        }
    }

    private function button(string $probe): mixed
    {
        return $this->observe(
            sprintf(
                '<div data-controller="shareButtonsPopup"><a class="btn-share" href="%s" data-action="shareButtonsPopup#open">Partager</a></div>',
                htmlspecialchars(self::HREF, \ENT_QUOTES)
            ),
            ['shareButtonsPopup' => 'share-buttons-popup'],
            'const link = () => root.querySelector("a");
             let prevented = false;
             link().addEventListener("click", (event) => { prevented = event.defaultPrevented; }); ' . $probe,
            [
                // Stood in for rather than opened: a window really opening would outlive the scenario and sit over every one that follows
                'before' => 'window.__opened = null;
                    window.open = (url, name, features) => { window.__opened = { url, name, features }; return null; };',
            ]
        );
    }
}
