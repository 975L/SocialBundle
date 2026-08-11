<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Assets;

use PHPUnit\Framework\TestCase;

// The scaffolded social.css is a hand-maintained copy of the token defaults, so it drifts on its own. This bundle declares no :root of its own, every token being read with an inline fallback, so the sass is the only place those defaults exist - and the only thing this file can be checked against
class ScaffoldThemeTest extends TestCase
{
    // Set per network by the @each at the bottom of _share-buttons.scss, so one value in :root would paint every button alike (the scaffold's own header says as much)
    private const array PER_NETWORK = [
        '--network-color',
    ];

    // Read with one fallback per shape or per breakpoint, so a single :root value collapses every variant into one look: the readme sends a design needing that to the app's own app.css instead
    private const array PER_VARIANT = [
        '--social-share-display',
        '--social-share-btn-width',
        '--social-share-btn-height',
        '--social-share-btn-radius',
    ];

    // Only read by .social-share--preview-backdrop, the stand-in band of the dashboard preview and the block gallery - never carried by a real page, so a site has nothing to retune there
    private const array PREVIEW_ONLY = [
        '--social-share-preview-background',
        '--social-share-preview-padding',
    ];

    // The space between buttons is already --social-share-gap's, so offering this one too would hand a design two ways to write the same look
    private const array NOT_THEMABLE = [
        '--social-share-btn-margin',
    ];

    // UiBundle's own page rhythm, read here so the social links block steps like every other block of the page - offered by its themes/ui.css, not restated here (the scaffold's header: only what is SocialBundle's own)
    private const array UI_OWNED = [
        '--section-space-tight',
    ];

    // A. Every token a site is meant to set is offered, so the file stays the single place to look
    public function testScaffoldOffersEveryThemableToken(): void
    {
        $missing = array_values(array_diff(
            array_keys($this->tokensReadFromSass()),
            array_keys($this->scaffoldTokens()),
            self::PER_NETWORK,
            self::PER_VARIANT,
            self::PREVIEW_ONLY,
            self::NOT_THEMABLE,
            self::UI_OWNED
        ));

        $this->assertSame([], $missing, sprintf(
            'The sass reads %s, which the scaffolded social.css never mentions: a design has no way to discover it short of reading the stylesheet.',
            implode(', ', $missing)
        ));
    }

    // B. What the scaffold shows as a default really is the one in force
    public function testScaffoldValuesMatchTheSassFallbacks(): void
    {
        $read = $this->tokensReadFromSass();
        $drifted = [];
        foreach ($this->scaffoldTokens() as $name => $value) {
            // Several fallbacks means a per-variant default, which no single value can mirror
            if (1 !== count($read[$name] ?? [])) {
                continue;
            }
            if ($read[$name][0] !== $value) {
                $drifted[] = sprintf('%s (sass: "%s", scaffold: "%s")', $name, $read[$name][0], $value);
            }
        }

        $this->assertSame([], $drifted, sprintf(
            "The scaffolded social.css no longer mirrors the sass:\n- %s",
            implode("\n- ", $drifted)
        ));
    }

    // C. Nothing is ever active, a value here outliving any later change to the bundle's default
    public function testScaffoldShipsEverythingCommentedOut(): void
    {
        $bare = (string) preg_replace('#/\*.*?\*/#s', '', $this->scaffoldSource());

        preg_match_all('/^\s*(--[a-z0-9-]+):/m', $bare, $matches);

        $this->assertSame([], $matches[1], sprintf(
            'The scaffolded social.css declares %s outside a comment: a fresh site would freeze that value instead of following the bundle.',
            implode(', ', $matches[1])
        ));
    }

    /**
     * Every "var(--x, …)" the bundle reads, mapped to its distinct fallbacks - the fallback being where such
     * a token's default lives. Nested ones are left out of the values, a per-variant token being skipped by
     * test B anyway.
     *
     * @return array<string, string[]>
     */
    private function tokensReadFromSass(): array
    {
        $tokens = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/sass'));

        foreach ($files as $file) {
            if ('scss' !== $file->getExtension()) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            preg_match_all('/var\(\s*(--[a-z0-9-]+)\s*[,)]/', $source, $names);
            foreach ($names[1] as $name) {
                $tokens[$name] ??= [];
            }

            preg_match_all('/var\(\s*(--[a-z0-9-]+),\s*([^(),]+?)\s*\)/', $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                if (!in_array($match[2], $tokens[$match[1]], true)) {
                    $tokens[$match[1]][] = $match[2];
                }
            }
        }

        $this->assertNotSame([], $tokens, 'No "var(--x, …)" found under sass/: the directory moved, and this test would pass blind.');

        return $tokens;
    }

    /**
     * The tokens the scaffold offers, every one of them commented out - which is what test C enforces.
     *
     * @return array<string, string>
     */
    private function scaffoldTokens(): array
    {
        preg_match_all('#/\* (--[a-z0-9-]+):\s*(.+?);\s*\*/#', $this->scaffoldSource(), $matches, PREG_SET_ORDER);

        $tokens = [];
        foreach ($matches as $match) {
            $tokens[$match[1]] = trim($match[2]);
        }

        return $tokens;
    }

    private function scaffoldSource(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2) . '/scaffold/assets/styles/themes/social.css');
    }
}
