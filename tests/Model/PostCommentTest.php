<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Model;

use c975L\SocialBundle\Model\PostComment;
use PHPUnit\Framework\TestCase;

class PostCommentTest extends TestCase
{
    // The newest first, an undated one last, cut at the limit
    public function testTheNewestComeFirst(): void
    {
        $old = new PostComment('a', 'Old', new \DateTimeImmutable('2026-11-01'));
        $new = new PostComment('b', 'New', new \DateTimeImmutable('2026-11-02'));
        $undated = new PostComment('c', 'Undated');

        $this->assertSame([$new, $old], PostComment::newest([$undated, $old, $new], 2));
        $this->assertSame([], PostComment::newest([$old], 0));
    }

    // A date a network gives unreadable is none
    public function testAnUnreadableDateIsNone(): void
    {
        $this->assertNull(PostComment::date('not a date'));
        $this->assertNull(PostComment::date(null));
        $this->assertSame('2026-11-02', PostComment::date('2026-11-02T10:00:00Z')?->format('Y-m-d'));
    }
}
