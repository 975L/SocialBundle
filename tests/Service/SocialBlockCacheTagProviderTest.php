<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Listener\SingletonBlockCacheInvalidationListener;
use c975L\SocialBundle\Service\SocialBlockCacheTagProvider;
use c975L\UiBundle\Entity\Block;
use c975L\UiBundle\Service\ReviewCollectionSourceProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

// What lets the pointer kind and the Google reviews be cached at all: each borrows the tag of what it renders
class SocialBlockCacheTagProviderTest extends TestCase
{
    public function testThePointerAndTheGoogleReviewsAreTheKindsCovered(): void
    {
        $this->assertSame(['social_links_display', 'google_reviews'], array_keys(new SocialBlockCacheTagProvider()->getCacheTagResolvers()));
    }

    // The reviews' own tag, emptied by UiBundle after every flush touching one - an import, a review hidden from the moderation list
    public function testTheGoogleReviewsCarryTheReviewsTag(): void
    {
        $resolvers = new SocialBlockCacheTagProvider()->getCacheTagResolvers();

        $this->assertSame([ReviewCollectionSourceProvider::CACHE_TAG], $resolvers['google_reviews'](new Block()));
    }

    // The tag has to be the very one SingletonBlockCacheInvalidationListener drops, or the pointer would keep showing links that were edited
    public function testTheTagIsTheOneTheSingletonDrops(): void
    {
        $resolvers = new SocialBlockCacheTagProvider()->getCacheTagResolvers();
        $tags = $resolvers['social_links_display'](new Block());

        $invalidated = [];
        $cache = $this->createStub(TagAwareCacheInterface::class);
        $cache->method('invalidateTags')->willReturnCallback(function (array $dropped) use (&$invalidated): bool {
            $invalidated = $dropped;

            return true;
        });

        $singleton = new Block();
        $singleton->setKind('social_links');
        new SingletonBlockCacheInvalidationListener($cache)
            ->postUpdate(new PostUpdateEventArgs($singleton, $this->createStub(EntityManagerInterface::class)));

        $this->assertSame($tags, $invalidated);
    }
}
