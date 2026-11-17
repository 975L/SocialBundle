<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Model;

use c975L\SocialBundle\Model\PostMedia;
use c975L\UiBundle\Model\SocialContent;
use PHPUnit\Framework\TestCase;

class PostMediaTest extends TestCase
{
    // The picture replaces the content's own, everything else kept, the content's alternative text taken when the picture has none
    public function testOnContentSwapsThePictureOnly(): void
    {
        $content = new SocialContent('1', 'Item 1', 'https://example.org/catalog/item-1', imagePath: '/tmp/item-1.webp', imageAlt: 'Item 1 alt', variables: ['key' => 'value'], locale: 'fr');

        $swapped = new PostMedia('/tmp/own.jpg', 'https://example.org/own.jpg', 'image/jpeg')->onContent($content);
        $this->assertSame(['/tmp/own.jpg', 'Item 1 alt', ['key' => 'value'], 'fr', 'https://example.org/catalog/item-1'], [$swapped->imagePath, $swapped->imageAlt, $swapped->variables, $swapped->locale, $swapped->url]);
        $this->assertSame('Own alt', new PostMedia('/tmp/own.jpg', 'https://example.org/own.jpg', 'image/jpeg', alt: 'Own alt')->onContent($content)->imageAlt);
    }
}
