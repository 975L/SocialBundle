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
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Service\SocialContentStatusProvider;
use c975L\UiBundle\Model\SocialContentStatus;
use PHPUnit\Framework\TestCase;

class SocialContentStatusProviderTest extends TestCase
{
    // A draft reserves its content at its planned moment, a post gone out anywhere publishes it at that moment
    public function testEachContentReadsAsReservedOrPublished(): void
    {
        $plannedAt = new \DateTimeImmutable('2026-10-10 09:00');
        $reserved = new SocialPost('gallery_media', '1', 'One', '', null, $plannedAt);
        new SocialPostTarget($reserved, 'bluesky', 'Text');
        $published = new SocialPost('gallery_media', '2', 'Two', '', null, $plannedAt);
        new SocialPostTarget($published, 'bluesky', 'Text')->markPublished('id');
        new SocialPostTarget($published, 'facebook', 'Text');

        $repository = $this->createStub(SocialPostRepository::class);
        $repository->method('findBySourceIds')->willReturn([$reserved, $published]);

        $statuses = new SocialContentStatusProvider($repository)->getStatuses('gallery_media', ['1', '2', '3']);

        $this->assertCount(2, $statuses);
        $this->assertArrayNotHasKey('3', $statuses);
        $this->assertEquals(new SocialContentStatus(SocialContentStatus::RESERVED, $plannedAt), $statuses['1']);
        $this->assertTrue($statuses['2']->isPublished());
    }
}
