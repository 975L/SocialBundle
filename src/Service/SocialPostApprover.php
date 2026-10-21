<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\SocialBundle\Entity\SocialPost;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Translation\TranslatableMessage;

// Several posts approved at once, each going out at its own moment: the list's selection and the calendar's series alike. One whose medias a network does not take is left as it is (see SocialMediaChecker), each caller saying why its own way
class SocialPostApprover
{
    public function __construct(
        private readonly SocialMediaChecker $mediaChecker,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    // The posts approved and saved, the ones refused handed back with their problems by network
    /**
     * @param iterable<SocialPost> $posts
     *
     * @return list<array{post: SocialPost, problems: array<string, list<TranslatableMessage>>}>
     */
    public function approveAll(iterable $posts): array
    {
        $refused = [];
        foreach ($posts as $post) {
            $problems = $this->mediaChecker->check($post);
            if ([] === $problems) {
                $post->approve();
            } else {
                $refused[] = ['post' => $post, 'problems' => $problems];
            }
        }
        $this->entityManager->flush();

        return $refused;
    }
}
