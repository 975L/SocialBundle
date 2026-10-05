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
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable('2026-10-05 10:00'));
        new SocialPostTarget($post, 'bluesky', 'Text');
        new SocialPostTarget($post, 'facebook', 'Text')->markFailed('Refused');
        new SocialPostTarget($post, 'instagram', 'Text')->markPublished('id');

        return $post;
    }

    // A draft and a failed target wait for the post's moment, a published one is never sent twice
    public function testApprovingHandsWhatIsNotOutYetToItsMoment(): void
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

    // Back to a draft, the approved targets wait for a reading again, the published one untouched
    public function testUnapprovingPutsTheApprovedTargetsBackToDraft(): void
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
        $post = new SocialPost('gallery_media', '42', 'Title', 'https://example.org/42', null, new \DateTimeImmutable('2026-10-05 10:00'));
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

    // An emptied field on the post's screen never leaves it without a moment
    public function testAPostKeepsItsMomentWhenTheFieldIsEmptied(): void
    {
        $post = $this->post();

        $post->setPlannedAt(null);
        $this->assertEquals(new \DateTimeImmutable('2026-10-05 10:00'), $post->getPlannedAt());

        $post->setPlannedAt(new \DateTimeImmutable('2026-10-06 09:15'));
        $this->assertEquals(new \DateTimeImmutable('2026-10-06 09:15'), $post->getPlannedAt());
    }

    // A post written on its screen is named by the first line of its text, cut, and says once that its text changed
    public function testAWrittenPostIsNamedByItsText(): void
    {
        $post = new SocialPost(SocialPost::SOURCE_MANUAL, 'abc', '', '', null, new \DateTimeImmutable('2026-10-05 10:00'));

        $post->setText("  Première ligne\nla suite  ");
        $this->assertTrue($post->isManual());
        $this->assertSame("Première ligne\nla suite", $post->getText());
        $this->assertSame('Première ligne', $post->getTitle());
        $this->assertTrue($post->takeTextChanged());
        $this->assertFalse($post->takeTextChanged());

        $post->setText("Première ligne\nla suite");
        $this->assertFalse($post->takeTextChanged());

        $post->setText(str_repeat('a', 100));
        $this->assertSame(str_repeat('a', 79) . '…', $post->getTitle());

        $post->setUrl(null);
        $this->assertSame('', $post->getUrl());
    }
}
