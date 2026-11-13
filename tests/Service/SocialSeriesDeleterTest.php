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
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Service\SocialPublisher;
use c975L\SocialBundle\Service\SocialSeriesDeleter;
use c975L\SocialBundle\Service\SocialSeriesGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

// A series deleted: its posts not gone out cancelled, those gone out kept outside it, its media removed once no post shows it
class SocialSeriesDeleterTest extends TestCase
{
    private string $projectDir;

    private SocialSeries $series;

    /** @var list<SocialPost> */
    private array $posts = [];

    /** @var list<SocialPost> */
    private array $cancelled = [];

    /** @var list<object> */
    private array $removed = [];

    // The post SocialPublisher::delete() finds being sent, refusing it
    private ?SocialPost $sending = null;

    // The post that goes out while SocialPublisher::delete() is asked for it
    private ?SocialPost $goesOut = null;

    // The posts still showing the series' media once it is deleted
    private int $references = 0;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/social-series-deleter-' . bin2hex(random_bytes(4));
        mkdir($this->projectDir . '/public/' . SocialSeriesGenerator::DIRECTORY, 0o777, true);
        $this->series = new SocialSeries('item-1', 3, 'days', 1, [], 'text', 'Text', [], ['bluesky']);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->projectDir . '/public/' . SocialSeriesGenerator::DIRECTORY . '/*') ?: []);
        rmdir($this->projectDir . '/public/' . SocialSeriesGenerator::DIRECTORY);
        rmdir($this->projectDir . '/public/medias/social');
        rmdir($this->projectDir . '/public/medias');
        rmdir($this->projectDir . '/public');
        rmdir($this->projectDir);
    }

    // The posts to come are cancelled, the series removed with its media
    public function testTheComingPostsAreCancelledWithTheMedia(): void
    {
        $first = $this->post();
        $second = $this->post();
        $media = $this->media();

        $this->assertSame(['cancelled' => 2, 'kept' => 0], $this->deleter()->count($this->series));
        $this->assertSame(['cancelled' => 2, 'kept' => 0], $this->deleter()->delete($this->series));
        $this->assertSame([$first, $second], $this->cancelled);
        $this->assertSame([$this->series], $this->removed);
        $this->assertFileDoesNotExist($media);
    }

    // A post gone out is kept, outside the series, and so is the media it shows
    public function testAPostGoneOutIsKeptOutsideTheSeries(): void
    {
        $gone = $this->post(true);
        $coming = $this->post();
        $media = $this->media();

        $this->assertSame(['cancelled' => 1, 'kept' => 1], $this->deleter()->count($this->series));
        $this->references = 1;

        $this->assertSame(['cancelled' => 1, 'kept' => 1], $this->deleter()->delete($this->series));
        $this->assertSame([$coming], $this->cancelled);
        $this->assertNull($gone->getSeries());
        $this->assertFileExists($media);
    }

    // A copy made outside the series keeps the media it shows
    public function testAMediaACopyShowsIsKept(): void
    {
        $this->post();
        $media = $this->media();
        $this->references = 1;

        $this->assertSame(['cancelled' => 1, 'kept' => 0], $this->deleter()->delete($this->series));
        $this->assertFileExists($media);
    }

    // A post that goes out while the series is deleted is kept as the others gone out
    public function testAPostGoneOutMeanwhileIsKept(): void
    {
        $this->goesOut = $this->post();

        $this->assertSame(['cancelled' => 0, 'kept' => 1], $this->deleter()->delete($this->series));
        $this->assertNull($this->goesOut->getSeries());
        $this->assertSame([$this->series], $this->removed);
    }

    // A post being sent keeps the series, for another try
    public function testAPostBeingSentKeepsTheSeries(): void
    {
        $this->post();
        $this->sending = $this->post();

        $this->assertNull($this->deleter()->delete($this->series));
        $this->assertSame([], $this->removed);
        $this->assertSame($this->series, $this->sending->getSeries());
    }

    // A media path outside the series' folder is left alone
    public function testAMediaOutsideTheSeriesFolderIsLeftAlone(): void
    {
        $this->post();
        $outside = $this->projectDir . '/public/outside.jpg';
        touch($outside);
        $this->series->setMedia(['outside.jpg', 'image/jpeg', 10, 10, null, 0]);

        $this->deleter()->delete($this->series);

        $this->assertFileExists($outside);
        unlink($outside);
    }

    // A post of the series on Bluesky, $goneOut already published there
    private function post(bool $goneOut = false): SocialPost
    {
        $post = new SocialPost('gallery_media', 'item-' . \count($this->posts), 'Title', 'https://example.org/item', null, new \DateTimeImmutable('+1 day'));
        $post->setSeries($this->series);
        $target = new SocialPostTarget($post, 'bluesky', 'Text');
        if ($goneOut) {
            $target->markPublished('id');
        }
        $this->posts[] = $post;

        return $post;
    }

    // The series' media written in its folder, the file returned
    private function media(): string
    {
        $path = SocialSeriesGenerator::DIRECTORY . '/item.jpg';
        touch($this->projectDir . '/public/' . $path);
        $this->series->setMedia([$path, 'image/jpeg', 10, 10, null, 0]);

        return $this->projectDir . '/public/' . $path;
    }

    // The deleter over the posts made, recording what it cancels and removes
    private function deleter(): SocialSeriesDeleter
    {
        $repository = $this->createStub(SocialPostRepository::class);
        $repository->method('findBy')->willReturnCallback(fn (): array => $this->posts);

        // As SocialPublisher::delete($post, true): refused while sent, or once gone out
        $publisher = $this->createStub(SocialPublisher::class);
        $publisher->method('delete')->willReturnCallback(function (SocialPost $post): bool {
            if ($post === $this->goesOut) {
                $target = $post->getTargets()->first();
                \assert($target instanceof SocialPostTarget);
                $target->markPublished('id');
            }
            if ($post === $this->sending || $post->hasGoneOut()) {
                return false;
            }
            $this->cancelled[] = $post;

            return true;
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            $this->removed[] = $entity;
        });

        $medias = $this->createStub(EntityRepository::class);
        $medias->method('count')->willReturnCallback(fn (): int => $this->references);
        $entityManager->method('getRepository')->willReturnMap([[SocialMedia::class, $medias]]);

        return new SocialSeriesDeleter($repository, $publisher, $entityManager, $this->projectDir);
    }
}
