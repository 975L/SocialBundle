<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Entity;

use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Enum\SocialPostStatus;
use PHPUnit\Framework\TestCase;

class SocialPostTest extends TestCase
{
    private function post(): SocialPost
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null);
        new SocialPostTarget($post, 'bluesky', 'Text');
        new SocialPostTarget($post, 'facebook', 'Text')->markFailed('Refused');
        new SocialPostTarget($post, 'instagram', 'Text')->markPublished('id');

        return $post;
    }

    // A draft and a failed target join the queue, a published one is never sent twice
    public function testApprovingQueuesWhatIsNotOutYet(): void
    {
        $post = $this->post();

        $post->approve();

        $this->assertSame(
            [SocialPostStatus::Approved, SocialPostStatus::Approved, SocialPostStatus::Published],
            $post->getTargets()->map(static fn (SocialPostTarget $target): SocialPostStatus => $target->getStatus())->getValues(),
        );
        $this->assertTrue($post->isApproved());
        $this->assertFalse($post->isApprovable());
    }

    // Taken out of the queue, the approved targets wait for a reading again, the published one untouched
    public function testUnapprovingPutsTheQueuedTargetsBackToDraft(): void
    {
        $post = $this->post();
        $post->approve();

        $post->unapprove();

        $this->assertSame(
            [SocialPostStatus::Draft, SocialPostStatus::Draft, SocialPostStatus::Published],
            $post->getTargets()->map(static fn (SocialPostTarget $target): SocialPostStatus => $target->getStatus())->getValues(),
        );
        $this->assertFalse($post->isApproved());
        $this->assertTrue($post->isApprovable());
    }

    // A post out everywhere has nothing left to approve
    public function testAPostPublishedEverywhereIsNotApprovable(): void
    {
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null);
        new SocialPostTarget($post, 'bluesky', 'Text')->markPublished('id');

        $this->assertFalse($post->isApprovable());
    }

    // Unticked, a text not out yet goes; a published one stays as the record of what went out; a network newly ticked waits for its text
    public function testTickingTheNetworksKeepsWhatWentOutAndAnnouncesTheNewOnes(): void
    {
        $post = $this->post();

        $post->setNetworks(['instagram', 'linkedin']);

        $this->assertSame(['instagram'], $post->getNetworks());
        $this->assertSame(['linkedin'], $post->takeAddedNetworks());
        $this->assertSame([], $post->takeAddedNetworks());
    }
}
