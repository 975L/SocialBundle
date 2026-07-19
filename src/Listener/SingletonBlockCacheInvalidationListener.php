<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\SocialBundle\Listener;

use c975L\UiBundle\Entity\Block;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

// "social_links" and "share_buttons_settings" are both non-pickable singleton kinds (see BlockRegistry's own "pickable" exclusion) - each edited through its own dedicated CRUD (SocialLinksCrudController/ShareButtonsSettingsCrudController), never through a Page's generic block picker, so there is exactly one Block row per kind, cached by SocialLinkExtension/ShareButtonsExtension under "singleton_block_{kind}"
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
class SingletonBlockCacheInvalidationListener
{
    private const CACHED_KINDS = ['social_links', 'share_buttons_settings'];

    public function __construct(private readonly TagAwareCacheInterface $cache) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->invalidate($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->invalidate($args->getObject());
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $this->invalidate($args->getObject());
    }

    private function invalidate(object $entity): void
    {
        if ($entity instanceof Block && in_array($entity->getKind(), self::CACHED_KINDS, true)) {
            $this->cache->invalidateTags(['singleton_block_' . $entity->getKind()]);
        }
    }
}
