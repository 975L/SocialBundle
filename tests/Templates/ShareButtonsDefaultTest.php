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

// The band a host layout includes on every page (see the fragment's own header comment), and the one place an editor is offered a way into its settings
class ShareButtonsDefaultTest extends TestCase
{
    private function template(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2) . '/templates/shareButtons/default.html.twig');
    }

    // UiBundle draws the hover button for whatever carries the url, but only mounts its controller on a ".blocks" collection - which a page composing no block at all never renders, the gallery's own screens among them
    public function testTheBandMountsTheOverlayControllerItself(): void
    {
        $template = $this->template();

        $this->assertStringContainsString('data-controller="blockEditOverlay"', $template);
        $this->assertStringContainsString('data-block-edit-url="{{ editUrl }}"', $template);
        $this->assertStringContainsString('share_buttons_edit_url()', $template);
    }

    // Resolving the url runs a query, and the button is an editor's alone: both are left out for everybody else
    public function testTheEditUrlIsResolvedForAnEditorOnly(): void
    {
        $this->assertStringContainsString(
            "{% set editUrl = is_granted(config('site-role-editor')) ? share_buttons_edit_url() : null %}",
            $this->template()
        );
    }
}
