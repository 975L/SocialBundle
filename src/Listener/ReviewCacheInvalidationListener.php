<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Listener;

use c975L\SocialBundle\Entity\Review;
use c975L\SocialBundle\Service\ReviewCollectionSourceProvider;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

// The cache tag ReviewCollectionSourceProvider declares on its items: a "collection" block showing reviews only renders fresh because this bundle empties the tag when its own entity changes, which a sync doing nothing else than persisting rows gets for free
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
class ReviewCacheInvalidationListener
{
    // The per-entity events only raise this flag: a sync importing 500 reviews would empty the same tag 500 times, where one call after the flush says exactly the same thing
    private bool $pending = false;

    public function __construct(private readonly TagAwareCacheInterface $cache)
    {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->markPending($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->markPending($args->getObject());
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $this->markPending($args->getObject());
    }

    // Invalidating here rather than on each entity also guarantees the tag is emptied after the rows are written, never before
    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->pending) {
            return;
        }

        $this->pending = false;
        $this->cache->invalidateTags([ReviewCollectionSourceProvider::CACHE_TAG]);
    }

    private function markPending(object $entity): void
    {
        if ($entity instanceof Review) {
            $this->pending = true;
        }
    }
}
