<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Entity\SocialMedia;
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

    // A photograph gone out with a post, then picked as a media of a later draft, stays published
    public function testAPublishedContentIsNotReservedAgainByALaterPost(): void
    {
        $published = new SocialPost('gallery_media', '5', 'Out', '', null, new \DateTimeImmutable('2026-10-03 09:00'));
        new SocialPostTarget($published, 'bluesky', 'Text')->markPublished('id');
        $draft = new SocialPost(SocialPost::SOURCE_MANUAL, 'abc', 'Later', '', null, new \DateTimeImmutable('2026-11-10 09:00'));
        new SocialPostTarget($draft, 'bluesky', 'Text');
        $draft->addMedia(new SocialMedia($draft)->setSource('gallery_media', '5'));

        // In the order of their planned moments, as the repository reads them
        $repository = $this->createStub(SocialPostRepository::class);
        $repository->method('findBySourceIds')->willReturn([$published, $draft]);

        $this->assertTrue(new SocialContentStatusProvider($repository)->getStatuses('gallery_media', ['5'])['5']->isPublished());
    }

    // A photograph picked as a media of a post is held as its content is - and freed once taken off it
    public function testAContentPickedAsAMediaIsHeldWhileItStaysOnThePost(): void
    {
        $plannedAt = new \DateTimeImmutable('2026-10-10 09:00');
        $post = new SocialPost(SocialPost::SOURCE_MANUAL, 'abc', 'Hello', '', null, $plannedAt);
        new SocialPostTarget($post, 'bluesky', 'Text');
        $picked = new SocialMedia($post)->setSource('gallery_media', '5');
        $post->addMedia($picked);
        $post->addMedia(new SocialMedia($post)->setSource('book', '6'));
        $post->addMedia(new SocialMedia($post)->setSource('gallery_media', '7'));

        $repository = $this->createStub(SocialPostRepository::class);
        $repository->method('findBySourceIds')->willReturn([$post]);
        $provider = new SocialContentStatusProvider($repository);

        // The post's own id belongs to another source, and "7" was not asked for
        $this->assertEquals(['5' => new SocialContentStatus(SocialContentStatus::RESERVED, $plannedAt)], $provider->getStatuses('gallery_media', ['5', '6', 'abc']));

        $post->removeMedia($picked);
        $this->assertSame([], $provider->getStatuses('gallery_media', ['5', '6', 'abc']));
    }
}
