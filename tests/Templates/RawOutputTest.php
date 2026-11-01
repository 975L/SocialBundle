<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Templates;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

// What an editor writes reaches a page through the sanitizer, never as it was typed
class RawOutputTest extends TestCase
{
    public function testNoTemplatePrintsAnythingRaw(): void
    {
        $raw = [];
        foreach (new Finder()->files()->in(\dirname(__DIR__, 2) . '/templates')->name('*.twig') as $file) {
            if (1 === preg_match('/\|\s*raw\b/', (string) preg_replace('/\{#.*?#\}/s', '', $file->getContents()))) {
                $raw[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $raw);
    }

    // The intro of a links block, in the block as in its back-office preview
    public function testTheIntroGoesThroughTheSanitizer(): void
    {
        foreach (['blocks/SocialLinks.html.twig', 'management/social_links_preview_theme.html.twig'] as $template) {
            $this->assertStringContainsString("{{ intro|sanitize_html('c975l.rich') }}", (string) file_get_contents(\dirname(__DIR__, 2) . '/templates/' . $template));
        }
    }
}
