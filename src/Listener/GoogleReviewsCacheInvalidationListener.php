<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Listener;

use c975L\ConfigBundle\Entity\Config;
use c975L\SocialBundle\Service\GoogleBusinessProfileSource;
use c975L\UiBundle\Service\ReviewCollectionSourceProvider;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

// The "google_reviews" block links to the Google listing, a setting its cached render knows nothing about: UiBundle's ReviewCacheInvalidationListener only empties the reviews' tag when a review changes, so a listing url typed or corrected would otherwise wait for the next import to show
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
class GoogleReviewsCacheInvalidationListener
{
    // The per-entity events only raise this flag: the back-office saves a whole config group at once, and one invalidation after the flush says the same thing
    private bool $stale = false;

    public function __construct(private readonly TagAwareCacheInterface $cache)
    {
    }

    // A config row added: flagged if it is the listing url
    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->markIfListingUrl($args->getObject());
    }

    // A config row changed: flagged if it is the listing url
    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->markIfListingUrl($args->getObject());
    }

    // A config row removed: flagged if it is the listing url
    public function postRemove(PostRemoveEventArgs $args): void
    {
        $this->markIfListingUrl($args->getObject());
    }

    // After the row is written, never before, so the next render reads the new url
    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->stale) {
            return;
        }

        $this->stale = false;
        $this->cache->invalidateTags([ReviewCollectionSourceProvider::CACHE_TAG]);
    }

    // Raises the flag when the entity is the listing url's config row
    private function markIfListingUrl(object $entity): void
    {
        if ($entity instanceof Config && GoogleBusinessProfileSource::LISTING_URL === $entity->getSlug()) {
            $this->stale = true;
        }
    }
}
