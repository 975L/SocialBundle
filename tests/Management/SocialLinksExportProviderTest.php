<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Management;

use c975L\SocialBundle\Management\SocialLinksExportProvider;
use c975L\UiBundle\Entity\Block;
use c975L\UiBundle\Repository\BlockRepository;
use PHPUnit\Framework\TestCase;

class SocialLinksExportProviderTest extends TestCase
{
    public function testGetKindReturnsSocialLinks(): void
    {
        $provider = new SocialLinksExportProvider($this->createStub(BlockRepository::class));

        $this->assertSame('social_links', $provider->getKind());
    }

    public function testExportAllReturnsEmptyItemsWhenNoSingletonBlockExistsYet(): void
    {
        $repository = $this->createStub(BlockRepository::class);
        $repository->method('findOneByKind')->willReturn(null);

        $data = (new SocialLinksExportProvider($repository))->exportAll();

        $this->assertSame(['items' => [], 'files' => []], $data);
    }

    public function testExportAllReturnsTheSingletonBlocksData(): void
    {
        $block = (new Block())->setKind('social_links')->setData(['links' => [['label' => 'X', 'url' => 'https://x.com', 'icon' => 'x']]]);

        $repository = $this->createStub(BlockRepository::class);
        $repository->method('findOneByKind')->willReturn($block);

        $data = (new SocialLinksExportProvider($repository))->exportAll();

        $this->assertSame([
            'items' => [['data' => ['links' => [['label' => 'X', 'url' => 'https://x.com', 'icon' => 'x']]]]],
            'files' => [],
        ], $data);
    }
}
