<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Enum;

use c975L\SocialBundle\Enum\SocialPostStatus;
use PHPUnit\Framework\TestCase;

class SocialPostStatusTest extends TestCase
{
    // EasyAdmin hands the badge callback the case name, its value or the case itself, depending on who asks
    public function testBadgeForAnswersEveryShapeEasyAdminHandsOver(): void
    {
        $this->assertSame('danger', SocialPostStatus::badgeFor(SocialPostStatus::Failed));
        $this->assertSame('danger', SocialPostStatus::badgeFor('failed'));
        $this->assertSame('danger', SocialPostStatus::badgeFor('Failed'));
        $this->assertSame('success', SocialPostStatus::badgeFor('published'));
    }

    // A badge is decoration: a status nobody recognises is not worth a 500 on the screen listing the posts
    public function testAnUnknownValueFallsBackOnTheDraftBadge(): void
    {
        $this->assertSame('warning', SocialPostStatus::badgeFor('unknown'));
        $this->assertSame('warning', SocialPostStatus::badgeFor(null));
    }
}
