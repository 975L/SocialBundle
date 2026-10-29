<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Listener;

use c975L\ConfigBundle\Entity\Config;
use c975L\SocialBundle\Listener\GoogleReviewsCacheInvalidationListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class GoogleReviewsCacheInvalidationListenerTest extends TestCase
{
    private function saveAndFlush(GoogleReviewsCacheInvalidationListener $listener, string $slug): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $listener->postUpdate(new PostUpdateEventArgs(new Config()->setSlug($slug), $entityManager));
        $listener->postFlush(new PostFlushEventArgs($entityManager));
    }

    // The block links to the listing, so a corrected url has to reach the cached render at once rather than at the next import
    public function testTheListingUrlEmptiesTheReviewsTagOnceFlushed(): void
    {
        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects($this->once())->method('invalidateTags')->with(['ui_reviews']);

        $this->saveAndFlush(new GoogleReviewsCacheInvalidationListener($cache), 'social-google-listing-url');
    }

    // Any other setting leaves the reviews' cache alone
    public function testAnotherConfigLeavesTheCacheAlone(): void
    {
        $cache = $this->createMock(TagAwareCacheInterface::class);
        $cache->expects($this->never())->method('invalidateTags');

        $this->saveAndFlush(new GoogleReviewsCacheInvalidationListener($cache), 'social-google-oauth-client-id');
    }
}
