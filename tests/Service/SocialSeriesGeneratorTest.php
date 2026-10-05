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
use c975L\SocialBundle\Service\SocialMediaFile;
use c975L\SocialBundle\Service\SocialPostWriter;
use c975L\SocialBundle\Service\SocialPublisher;
use c975L\SocialBundle\Service\SocialSeriesGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\File;

class SocialSeriesGeneratorTest extends TestCase
{
    /** @var list<array{0: list<\DateTimeImmutable>, 1: list<string>}> */
    private array $drafted = [];

    // A publisher writing each post's texts from its own, Bluesky and LinkedIn connected
    private function generator(array $variants = []): SocialSeriesGenerator
    {
        $publisher = $this->createStub(SocialPublisher::class);
        $publisher->method('createManual')->willReturnCallback(static function (\DateTimeImmutable $at): SocialPost {
            $post = new SocialPost(SocialPost::SOURCE_MANUAL, bin2hex(random_bytes(4)), '', '', null, $at);
            new SocialPostTarget($post, 'bluesky', '');
            new SocialPostTarget($post, 'linkedin', '');

            return $post;
        });
        $publisher->method('rewriteTargets')->willReturnCallback(static function (SocialPost $post): void {
            foreach ($post->getTargets() as $target) {
                $target->setText((string) $post->getText());
            }
        });
        $publisher->method('getMaxLength')->willReturnCallback(static fn (string $network): ?int => ['bluesky' => 300, 'linkedin' => 3000][$network] ?? null);
        $publisher->method('prepareDrafts')->willReturnCallback(function (array $moments, array $sources): array {
            $this->drafted[] = [$moments, $sources];

            return [];
        });

        $writer = $this->createStub(SocialPostWriter::class);
        $writer->method('variants')->willReturnCallback(static fn (string $instruction, int $count, int $maxLength): array => array_map(static fn (string $text): string => $text . ' ' . $maxLength, $variants));

        return new SocialSeriesGenerator($publisher, $writer, new SocialMediaFile(), $this->createStub(EntityManagerInterface::class), sys_get_temp_dir());
    }

    // Every day, week or month from the start, a month keeping its day
    public function testTheMomentsFollowThePace(): void
    {
        $start = new \DateTimeImmutable('2026-10-31 09:00');

        $this->assertEquals([$start, new \DateTimeImmutable('2026-11-07 09:00'), new \DateTimeImmutable('2026-11-14 09:00')], $this->generator()->moments($start, 3, 'week'));
        $this->assertEquals([$start, new \DateTimeImmutable('2026-11-01 09:00')], $this->generator()->moments($start, 2, 'day'));
        $this->assertCount(4, $this->generator()->moments($start, 4, 'month'));
    }

    // One text for every draft, on the networks ticked only, each at its moment
    public function testOneTextMakesADraftPerMoment(): void
    {
        $moments = $this->generator()->moments(new \DateTimeImmutable('2026-11-01 09:00'), 3, 'day');

        $posts = $this->generator()->generate($moments, SocialSeriesGenerator::MODE_TEXT, ['bluesky'], 'Bonjour');

        $this->assertCount(3, $posts);
        $this->assertSame([['bluesky'], 'Bonjour', 'Bonjour'], [$posts[0]->getNetworks(), $posts[2]->getText(), $posts[2]->getTargets()->first()->getText()]);
        $this->assertEquals($moments[2], $posts[2]->getPlannedAt());
        $this->assertFalse($posts[0]->isApproved());
    }

    // The series' picture written once as a JPEG, each draft referencing it with its size, so the networks' weight limits are checked
    public function testTheMediaIsStoredOnceAndReferencedWithItsSize(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'series');
        imagejpeg(imagecreatetruecolor(40, 30), $path);
        $moments = $this->generator()->moments(new \DateTimeImmutable('2026-11-01 09:00'), 2, 'day');

        $posts = $this->generator()->generate($moments, SocialSeriesGenerator::MODE_TEXT, ['bluesky'], 'Bonjour', [], new File($path));

        $first = $posts[0]->getMedias()->first();
        $stored = sys_get_temp_dir() . '/public/' . $first->getFilename();
        try {
            $this->assertTrue($first->isReference());
            $this->assertSame($first->getFilename(), $posts[1]->getMedias()->first()->getFilename());
            $this->assertSame(filesize($stored), $first->getSize());
            $this->assertSame([40, 30], [$first->getWidth(), $first->getHeight()]);
        } finally {
            @unlink($stored);
        }
    }

    // The AI writes for the shortest network ticked, and fewer texts make fewer drafts
    public function testTheAiWritesEachTextForTheShortestNetwork(): void
    {
        $moments = $this->generator()->moments(new \DateTimeImmutable('2026-11-01 09:00'), 3, 'day');

        $posts = $this->generator(['Un', 'Deux'])->generate($moments, SocialSeriesGenerator::MODE_AI, ['bluesky', 'linkedin'], 'Une photo par jour');

        $this->assertSame(['Un 300', 'Deux 300'], array_map(static fn (SocialPost $post): ?string => $post->getText(), $posts));
    }

    // The site's contents are prepared one per moment, among the sources picked
    public function testTheSourcesPrepareOneDraftPerMoment(): void
    {
        $moments = $this->generator()->moments(new \DateTimeImmutable('2026-11-01 09:00'), 2, 'week');
        $generator = $this->generator();

        $generator->generate($moments, SocialSeriesGenerator::MODE_SOURCE, ['bluesky'], '', ['gallery_media:3']);

        $this->assertEquals([[$moments, ['gallery_media:3']]], $this->drafted);
    }
}
