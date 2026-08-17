<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Listener;

use c975L\SocialBundle\Entity\Review;
use c975L\SocialBundle\Listener\ReviewCacheInvalidationListener;
use c975L\SocialBundle\Service\ReviewCollectionSourceProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class ReviewCacheInvalidationListenerTest extends TestCase
{
    private function createEntityManager(): EntityManagerInterface
    {
        return $this->createStub(EntityManagerInterface::class);
    }

    // A sync does nothing but persist rows, so a collection block showing reviews only renders fresh because of this listener
    public function testPostPersistInvalidatesTheReviewsTagOnFlush(): void
    {
        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects($this->once())->method('invalidateTags')->with([ReviewCollectionSourceProvider::CACHE_TAG]);

        $listener = new ReviewCacheInvalidationListener($cache);
        $listener->postPersist(new PostPersistEventArgs(new Review(), $this->createEntityManager()));
        $listener->postFlush(new PostFlushEventArgs($this->createEntityManager()));
    }

    // A reply written in the back office is an update, and the page showing it has to follow
    public function testPostUpdateInvalidatesTheReviewsTagOnFlush(): void
    {
        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects($this->once())->method('invalidateTags')->with([ReviewCollectionSourceProvider::CACHE_TAG]);

        $listener = new ReviewCacheInvalidationListener($cache);
        $listener->postUpdate(new PostUpdateEventArgs(new Review(), $this->createEntityManager()));
        $listener->postFlush(new PostFlushEventArgs($this->createEntityManager()));
    }

    public function testPreRemoveInvalidatesTheReviewsTagOnFlush(): void
    {
        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects($this->once())->method('invalidateTags')->with([ReviewCollectionSourceProvider::CACHE_TAG]);

        $listener = new ReviewCacheInvalidationListener($cache);
        $listener->preRemove(new PreRemoveEventArgs(new Review(), $this->createEntityManager()));
        $listener->postFlush(new PostFlushEventArgs($this->createEntityManager()));
    }

    // An import of many reviews empties the tag once, not once per row
    public function testTheTagIsInvalidatedOnceWhateverTheNumberOfReviewsSaved(): void
    {
        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects($this->once())->method('invalidateTags');

        $listener = new ReviewCacheInvalidationListener($cache);

        for ($i = 0; $i < 5; ++$i) {
            $listener->postPersist(new PostPersistEventArgs(new Review(), $this->createEntityManager()));
        }

        $listener->postFlush(new PostFlushEventArgs($this->createEntityManager()));
    }

    // A flush carrying no review of its own must not empty a tag nothing changed
    public function testASecondFlushWithoutAReviewInvalidatesNothing(): void
    {
        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects($this->once())->method('invalidateTags');

        $listener = new ReviewCacheInvalidationListener($cache);
        $listener->postPersist(new PostPersistEventArgs(new Review(), $this->createEntityManager()));
        $listener->postFlush(new PostFlushEventArgs($this->createEntityManager()));
        $listener->postFlush(new PostFlushEventArgs($this->createEntityManager()));
    }

    // The listener sees every entity the application saves, and only this bundle's own tag is its business
    public function testInvalidateIsSkippedForEntitiesThatAreNotReviews(): void
    {
        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects($this->never())->method('invalidateTags');

        $listener = new ReviewCacheInvalidationListener($cache);
        $listener->postUpdate(new PostUpdateEventArgs(new \stdClass(), $this->createEntityManager()));
        $listener->postFlush(new PostFlushEventArgs($this->createEntityManager()));
    }
}
