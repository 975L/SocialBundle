<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Management;

use c975L\SocialBundle\Management\ShareButtonsSettingsExportProvider;
use c975L\UiBundle\Entity\Block;
use c975L\UiBundle\Repository\BlockRepository;
use PHPUnit\Framework\TestCase;

class ShareButtonsSettingsExportProviderTest extends TestCase
{
    public function testGetKindReturnsShareButtonsSettings(): void
    {
        $provider = new ShareButtonsSettingsExportProvider($this->createStub(BlockRepository::class));

        $this->assertSame('share_buttons_settings', $provider->getKind());
    }

    public function testExportAllReturnsEmptyItemsWhenNoSingletonBlockExistsYet(): void
    {
        $repository = $this->createStub(BlockRepository::class);
        $repository->method('findOneByKind')->willReturn(null);

        $data = (new ShareButtonsSettingsExportProvider($repository))->exportAll();

        $this->assertSame(['items' => [], 'files' => []], $data);
    }

    public function testExportAllReturnsTheSingletonBlocksData(): void
    {
        $block = (new Block())->setKind('share_buttons_settings')->setData(['networks' => ['facebook', 'x'], 'style' => 'rounded']);

        $repository = $this->createStub(BlockRepository::class);
        $repository->method('findOneByKind')->willReturn($block);

        $data = (new ShareButtonsSettingsExportProvider($repository))->exportAll();

        $this->assertSame([
            'items' => [['data' => ['networks' => ['facebook', 'x'], 'style' => 'rounded']]],
            'files' => [],
        ], $data);
    }
}
