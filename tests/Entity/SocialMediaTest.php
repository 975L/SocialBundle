<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Entity;

use c975L\SocialBundle\Entity\SocialMedia;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\File;

class SocialMediaTest extends TestCase
{
    // A content is named by its type and its id together, half of it naming nothing
    public function testTheSourceIsKeptWholeOrNotAtAll(): void
    {
        $media = new SocialMedia()->setSource('gallery_media', '97');
        $this->assertSame(['gallery_media', '97'], [$media->getSourceType(), $media->getSourceId()]);

        $media->setSource('gallery_media', null);
        $this->assertSame([null, null], [$media->getSourceType(), $media->getSourceId()]);
    }

    // Replaced by an upload, the media is no longer the photograph it was picked from, which it frees
    public function testAnUploadFreesTheContentTheMediaWasPickedFrom(): void
    {
        $media = new SocialMedia()->setSource('gallery_media', '97');

        $media->setFile(null);
        $this->assertSame('97', $media->getSourceId());

        $media->setFile(new File(__FILE__));
        $this->assertNull($media->getSourceType());
        $this->assertNull($media->getSourceId());
    }
}
