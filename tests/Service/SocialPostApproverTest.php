<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Enum\SocialPostStatus;
use c975L\SocialBundle\Service\SocialMediaChecker;
use c975L\SocialBundle\Service\SocialPostApprover;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\TranslatableMessage;

class SocialPostApproverTest extends TestCase
{
    // The posts whose medias suit are approved and saved at once, the one refused handed back with its problems and left a draft
    public function testThePostsAreApprovedTheRefusedOneHandedBack(): void
    {
        $posts = [];
        foreach (['ok', 'refused'] as $id) {
            $posts[$id] = new SocialPost('manual', $id, $id, '', null, new \DateTimeImmutable('+1 day'));
            new SocialPostTarget($posts[$id], 'bluesky', 'Text');
        }
        $problems = ['instagram' => [new TranslatableMessage('label.social_media_required')]];
        $checker = $this->createStub(SocialMediaChecker::class);
        $checker->method('check')->willReturnCallback(static fn (SocialPost $post): array => $post === $posts['refused'] ? $problems : []);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');

        $refused = new SocialPostApprover($checker, $entityManager)->approveAll($posts);

        $this->assertSame([['post' => $posts['refused'], 'problems' => $problems]], $refused);
        $this->assertSame(SocialPostStatus::Approved, $posts['ok']->getTargets()->first()?->getStatus());
        $this->assertSame('draft', $posts['refused']->getState());
    }
}
