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

// "text" is the one icon style showing no glyph at all, the network's name standing as the link - a footer row
// set as words. It spans a template, a stylesheet and the back-office preview's own script, and it only holds
// together if the three agree: hence one test over the three files rather than three unrelated ones
class SocialLinksTextStyleTest extends TestCase
{
    private function read(string $path): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2) . '/' . $path);
    }

    // The glyph is dropped from the markup itself, not merely hidden: a row set as words has no icon to download
    public function testTheTemplatePrintsNoIconUnderTheTextStyle(): void
    {
        $template = $this->read('templates/blocks/SocialLinks.html.twig');

        $this->assertStringContainsString("{% set textOnly = iconStyle|default('minimal') == 'text' %}", $template);
        $this->assertStringContainsString('{% if not textOnly and icon is not empty %}', $template);
    }

    // With the glyph gone the label is all that is left: an entry showing neither would have nothing to click,
    // so the "display label" box loses its say under this style rather than being able to empty the row
    public function testTheTemplateForcesTheLabelUnderTheTextStyle(): void
    {
        $template = $this->read('templates/blocks/SocialLinks.html.twig');

        $this->assertStringContainsString('{% if textOnly or displayLabel is not defined or displayLabel %}', $template);
    }

    // A custom entry may be saved with no label at all: under the text style the glyph is gone too, so without
    // this fallback the row would render an empty <a> - nothing to click, and an empty accessible name everywhere else
    public function testBothTemplatesFallBackToTheUrlWhenTheCustomLabelIsEmpty(): void
    {
        foreach (['templates/blocks/SocialLinks.html.twig', 'templates/management/social_links_preview_theme.html.twig'] as $file) {
            $template = $this->read($file);

            $this->assertStringContainsString('link.customLabel ?: link.url', $template, sprintf('"%s" names nothing when a custom entry carries no label.', $file));
        }
    }

    // The preview's own markup must already say what the real render says: hiding the label server-side under
    // "text" would show a row of empty items until the script catches up on DOMContentLoaded
    public function testThePreviewKeepsTheLabelUnderTheTextStyle(): void
    {
        $template = $this->read('templates/management/social_links_preview_theme.html.twig');

        $this->assertStringContainsString("{% if not display_label and icon_style != 'text' %} hidden{% endif %}", $template);
    }

    // The pill belongs to the glyph: drawn around a bare word, a background and a radius read as a button
    public function testTheStylesheetStripsThePillFromTheTextStyle(): void
    {
        foreach (['public/css/styles.css', 'public/css/styles.min.css'] as $file) {
            $css = $this->read($file);

            $this->assertStringContainsString('.social-links--text', $css, sprintf('"%s" ships no rule for the text style - rebuild it from sass/_social.scss.', $file));
        }
    }

    // The back-office preview renders every glyph and lets CSS decide (see social_links_preview_theme.html.twig),
    // where the real row prints none - without this rule an editor would be shown a style the page never renders
    public function testTheStylesheetHidesThePreviewIconsUnderTheTextStyle(): void
    {
        $css = $this->read('sass/_social.scss');

        $this->assertMatchesRegularExpression('/\.social-links--text \.social-link-icon \{\s*display: none;/', $css);
    }

    // The preview swaps the list's modifier class from a list of its own: a style missing from it leaves the
    // previous style's class in place, showing the editor the one they just moved away from
    public function testThePreviewScriptKnowsTheTextStyle(): void
    {
        $script = $this->read('assets/js/social-links-preview.js');

        $this->assertStringContainsString("const ICON_STYLES = ['minimal', 'colored', 'outline', 'text'];", $script);
        // Same rule as the template's, so the preview does not show an empty row where the page shows the names
        $this->assertStringContainsString("styleSelect.value === 'text'", $script);
    }
}
