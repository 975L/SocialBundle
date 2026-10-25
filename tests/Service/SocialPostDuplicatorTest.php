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
use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Namer\SocialMediaNamer;
use c975L\SocialBundle\Service\SocialPostDuplicator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

// A post written again the day after: a draft of no series, its texts on the networks still connected, its medias its own
class SocialPostDuplicatorTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/social-duplicator-' . bin2hex(random_bytes(4));
        mkdir($this->projectDir . '/public/' . SocialMediaNamer::DIRECTORY, 0o755, true);
        file_put_contents($this->projectDir . '/public/' . SocialMediaNamer::DIRECTORY . '/upload.jpg', 'picture');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->projectDir);
    }

    private function post(string $plannedAt): SocialPost
    {
        $post = new SocialPost(SocialPost::SOURCE_MANUAL, 'none', 'Title', 'https://example.org', null, new \DateTimeImmutable($plannedAt));
        $post->setText('Title');
        $post->setSeries(new SocialSeries('Series', 3, 'daily', 1, [], 'text', 'Text', [], ['bluesky']));

        return $post;
    }

    // The texts and first comments copied as they read, on the connected networks only, every one a draft again - one gone out as well
    public function testTheCopyIsADraftOfNoSeriesTheDayAfter(): void
    {
        $post = $this->post('+2 days 09:30');
        $bluesky = new SocialPostTarget($post, 'bluesky', 'Bluesky text');
        $bluesky->markPublished('ext-1');
        $bluesky->setComment('Read more');
        new SocialPostTarget($post, 'facebook', 'Facebook text');

        $copy = new SocialPostDuplicator($this->projectDir)->duplicate($post, ['bluesky', 'linkedin']);

        $this->assertEquals($post->getPlannedAt()->modify('+1 day'), $copy->getPlannedAt());
        $this->assertSame('draft', $copy->getState());
        $this->assertNull($copy->getSeries());
        $this->assertSame(['Title', 'Title'], [$copy->getTitle(), $copy->getText()]);
        $this->assertSame(['bluesky' => 'Bluesky text'], array_combine($copy->getNetworks(), $copy->getTargets()->map(static fn (SocialPostTarget $target): string => $target->getText())->getValues()));
        $this->assertSame('Read more', $copy->getTargets()->first()->getComment());
    }

    // A post whose next day is gone already is copied to tomorrow, at its time of day
    public function testAPastPostIsCopiedToTomorrow(): void
    {
        $copy = new SocialPostDuplicator($this->projectDir)->duplicate($this->post('-10 days 18:45'), []);

        $this->assertEquals(new \DateTimeImmutable('tomorrow 18:45'), $copy->getPlannedAt());
    }

    // An upload gets a file of its own, a reference stays one, and an upload no longer on disk is left out
    public function testTheMediasAreTheCopysOwn(): void
    {
        $post = $this->post('+1 day 10:00');
        $upload = new SocialMedia($post);
        $upload->setFilename(SocialMediaNamer::DIRECTORY . '/upload.jpg')->setMimeType('image/jpeg')->setAlt('Alt')->setPosition(1);
        $post->addMedia($upload);
        $reference = SocialMedia::reference($post, '/medias/gallery/lac.jpg', 'image/jpeg', 800, 600);
        $reference->setSource('gallery_media', '7');
        $post->addMedia($reference->setPosition(0));
        $gone = new SocialMedia($post);
        $post->addMedia($gone->setFilename(SocialMediaNamer::DIRECTORY . '/gone.jpg'));

        $medias = new SocialPostDuplicator($this->projectDir)->duplicate($post, [])->getMedias()->getValues();

        $this->assertCount(2, $medias);
        [$copiedUpload, $copiedReference] = $medias;
        $this->assertFalse($copiedUpload->isReference());
        $this->assertNotSame($upload->getFilename(), $copiedUpload->getFilename());
        $this->assertStringStartsWith(SocialMediaNamer::DIRECTORY . '/', (string) $copiedUpload->getFilename());
        $this->assertSame('picture', file_get_contents($this->projectDir . '/public/' . $copiedUpload->getFilename()));
        $this->assertSame(['Alt', 1, 'image/jpeg'], [$copiedUpload->getAlt(), $copiedUpload->getPosition(), $copiedUpload->getMimeType()]);
        $this->assertTrue($copiedReference->isReference());
        $this->assertSame(['medias/gallery/lac.jpg', 'gallery_media', '7', 800, 0], [$copiedReference->getFilename(), $copiedReference->getSourceType(), $copiedReference->getSourceId(), $copiedReference->getWidth(), $copiedReference->getPosition()]);
    }
}
