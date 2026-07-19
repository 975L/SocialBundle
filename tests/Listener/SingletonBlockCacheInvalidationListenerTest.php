<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Listener;

use c975L\SocialBundle\Listener\SingletonBlockCacheInvalidationListener;
use c975L\UiBundle\Entity\Block;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class SingletonBlockCacheInvalidationListenerTest extends TestCase
{
    public function testPostPersistInvalidatesTheSocialLinksTag(): void
    {
        $block = (new Block())->setKind('social_links');

        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects($this->once())->method('invalidateTags')->with(['singleton_block_social_links']);

        (new SingletonBlockCacheInvalidationListener($cache))
            ->postPersist(new PostPersistEventArgs($block, $this->createStub(EntityManagerInterface::class)));
    }

    public function testPostUpdateInvalidatesTheShareButtonsSettingsTag(): void
    {
        $block = (new Block())->setKind('share_buttons_settings');

        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects($this->once())->method('invalidateTags')->with(['singleton_block_share_buttons_settings']);

        (new SingletonBlockCacheInvalidationListener($cache))
            ->postUpdate(new PostUpdateEventArgs($block, $this->createStub(EntityManagerInterface::class)));
    }

    public function testPreRemoveInvalidatesTheMatchingTag(): void
    {
        $block = (new Block())->setKind('social_links');

        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects($this->once())->method('invalidateTags')->with(['singleton_block_social_links']);

        (new SingletonBlockCacheInvalidationListener($cache))
            ->preRemove(new PreRemoveEventArgs($block, $this->createStub(EntityManagerInterface::class)));
    }

    // A Block of any other kind is never one of these two singletons - nothing to invalidate here
    public function testInvalidateIsSkippedForBlocksOfAnotherKind(): void
    {
        $block = (new Block())->setKind('menu_link');

        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects($this->never())->method('invalidateTags');

        (new SingletonBlockCacheInvalidationListener($cache))
            ->postUpdate(new PostUpdateEventArgs($block, $this->createStub(EntityManagerInterface::class)));
    }

    public function testInvalidateIsSkippedForEntitiesThatAreNotBlocks(): void
    {
        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects($this->never())->method('invalidateTags');

        (new SingletonBlockCacheInvalidationListener($cache))
            ->postUpdate(new PostUpdateEventArgs(new \stdClass(), $this->createStub(EntityManagerInterface::class)));
    }
}
