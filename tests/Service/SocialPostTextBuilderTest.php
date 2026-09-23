<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Service\SocialPostTextBuilder;
use c975L\UiBundle\Model\SocialContent;
use PHPUnit\Framework\TestCase;

class SocialPostTextBuilderTest extends TestCase
{
    private function build(?string $template, SocialContent $content, int $maxLength = 300): string
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap([['social-publish-template', $template]]);

        return new SocialPostTextBuilder($configService)->build($content, $maxLength);
    }

    public function testASiteWithNoTemplatePostsTheTitleAndTheUrl(): void
    {
        $content = new SocialContent('1', 'Lac d\'Annecy', 'https://example.org/photo');

        $this->assertSame("Lac d'Annecy\n\nhttps://example.org/photo", $this->build(null, $content));
    }

    public function testTheContentsOwnVariablesFillTheTemplate(): void
    {
        $content = new SocialContent('1', 'Lac', 'https://example.org/photo', variables: ['category' => 'Montagne']);

        $this->assertSame('Lac (Montagne) https://example.org/photo', $this->build('{title} ({category}) {url}', $content));
    }

    // A photo with no description must not post "{description}" nor leave a hole of blank lines where it would have been
    public function testAPlaceholderWithNoValueIsDropped(): void
    {
        $content = new SocialContent('1', 'Lac', 'https://example.org/photo');

        $this->assertSame("Lac\n\nhttps://example.org/photo", $this->build("{title}\n\n{description}\n\n{url}", $content));
    }

    // Each network gets its text cut to what it accepts, marked cut so the reviewer sees it was
    public function testTheTextIsCutToTheNetworksLimit(): void
    {
        $content = new SocialContent('1', str_repeat('é', 50), 'https://example.org/photo');

        $text = $this->build('{title}', $content, 20);

        $this->assertSame(20, mb_strlen($text));
        $this->assertStringEndsWith('…', $text);
    }
}
