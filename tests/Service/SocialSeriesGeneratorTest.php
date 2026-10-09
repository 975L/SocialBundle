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
use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Repository\SocialSeriesRepository;
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
    private function generator(array $variants = [], ?\DateTimeImmutable $lastPlannedAt = null): SocialSeriesGenerator
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
        // Two photographs left in the galleries picked, then none
        $photos = ['12', '34'];
        $publisher->method('createFromSources')->willReturnCallback(static function (\DateTimeImmutable $at) use (&$photos): ?SocialPost {
            $id = array_shift($photos);

            return null === $id ? null : new SocialPost('gallery_media', $id, 'Photo ' . $id, 'https://example.org/photo-' . $id, 'https://example.org/' . $id . '.jpg', $at);
        });
        $publisher->method('getMaxLength')->willReturnCallback(static fn (string $network): ?int => ['bluesky' => 300, 'linkedin' => 3000][$network] ?? null);
        $publisher->method('prepareDrafts')->willReturnCallback(function (array $moments, array $sources): array {
            $this->drafted[] = [$moments, $sources];

            return [];
        });

        $writer = $this->createStub(SocialPostWriter::class);
        $writer->method('variants')->willReturnCallback(static fn (string $instruction, int $count, int $maxLength): array => array_map(static fn (string $text): string => $text . ' ' . $maxLength, $variants));

        $seriesRepository = $this->createStub(SocialSeriesRepository::class);
        $seriesRepository->method('findLastPlannedAt')->willReturn($lastPlannedAt);

        return new SocialSeriesGenerator($publisher, $writer, new SocialMediaFile(), $this->createStub(EntityManagerInterface::class), $seriesRepository, sys_get_temp_dir());
    }

    // A series as the form makes it
    /**
     * @param list<int>    $weekdays
     * @param list<string> $sources
     * @param list<string> $networks
     */
    private function series(int $count, string $mode, array $networks, string $text = '', array $sources = [], string $frequency = 'days', int $interval = 1, array $weekdays = []): SocialSeries
    {
        return new SocialSeries('Series', $count, $frequency, $interval, $weekdays, $mode, $text, $sources, $networks);
    }

    // Every N days or every month from the start, a month keeping its day
    public function testTheMomentsFollowThePace(): void
    {
        $start = new \DateTimeImmutable('2026-10-31 09:00');

        $this->assertEquals([$start, new \DateTimeImmutable('2026-11-07 09:00'), new \DateTimeImmutable('2026-11-14 09:00')], $this->generator()->moments($start, 3, 'days', 7));
        $this->assertEquals([$start, new \DateTimeImmutable('2026-11-01 09:00')], $this->generator()->moments($start, 2, 'days'));
        $this->assertEquals([$start, new \DateTimeImmutable('2026-11-03 09:00')], $this->generator()->moments($start, 2, 'days', 3));
        $this->assertCount(4, $this->generator()->moments($start, 4, 'month'));

        $end = new \DateTimeImmutable('2027-01-31 09:00');
        $this->assertEquals([$end, new \DateTimeImmutable('2027-02-28 09:00'), new \DateTimeImmutable('2027-03-31 09:00')], $this->generator()->moments($end, 3, 'month'));
    }

    // Some days of the week, the first of them from the start - a Saturday start on Mondays and Wednesdays beginning on the Monday after
    public function testTheMomentsFallOnTheDaysOfTheWeekTicked(): void
    {
        $moments = $this->generator()->moments(new \DateTimeImmutable('2026-10-31 21:00'), 3, 'weekdays', 1, [1, 3]);

        $this->assertEquals([new \DateTimeImmutable('2026-11-02 21:00'), new \DateTimeImmutable('2026-11-04 21:00'), new \DateTimeImmutable('2026-11-09 21:00')], $moments);
    }

    // One text for every draft, on the networks ticked only, each at its moment
    public function testOneTextMakesADraftPerMoment(): void
    {
        $moments = $this->generator()->moments(new \DateTimeImmutable('2026-11-01 09:00'), 3, 'days');

        $series = $this->series(3, SocialSeriesGenerator::MODE_TEXT, ['bluesky'], 'Bonjour');
        $posts = $this->generator()->generate($series, $moments[0]);

        $this->assertCount(3, $posts);
        $this->assertSame([['bluesky'], 'Bonjour', 'Bonjour'], [$posts[0]->getNetworks(), $posts[2]->getText(), $posts[2]->getTargets()->first()->getText()]);
        $this->assertEquals($moments[2], $posts[2]->getPlannedAt());
        $this->assertFalse($posts[0]->isApproved());
        $this->assertSame($series, $posts[2]->getSeries());
    }

    // The series' picture written once as a JPEG, each draft referencing it with its size, so the networks' weight limits are checked
    public function testTheMediaIsStoredOnceAndReferencedWithItsSize(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'series');
        imagejpeg(imagecreatetruecolor(40, 30), $path);
        $moments = $this->generator()->moments(new \DateTimeImmutable('2026-11-01 09:00'), 2, 'days');

        $posts = $this->generator()->generate($this->series(2, SocialSeriesGenerator::MODE_TEXT, ['bluesky'], 'Bonjour'), $moments[0], new File($path));

        $first = $posts[0]->getMedias()->first();
        $stored = sys_get_temp_dir() . '/public/' . $first->getFilename();
        try {
            $this->assertTrue($first->isReference());
            $this->assertSame($first->getFilename(), $posts[1]->getMedias()->first()->getFilename());
            $this->assertSame(filesize($stored), $first->getSize());
            $this->assertSame([40, 30], [$first->getWidth(), $first->getHeight()]);
            $this->assertSame($first->getFilename(), $posts[0]->getSeries()?->getMedia()[0] ?? null);
        } finally {
            @unlink($stored);
        }
    }

    // The AI writes for the shortest network ticked, and fewer texts make fewer drafts
    public function testTheAiWritesEachTextForTheShortestNetwork(): void
    {
        $moments = $this->generator()->moments(new \DateTimeImmutable('2026-11-01 09:00'), 3, 'days');

        $posts = $this->generator(['Un', 'Deux'])->generate($this->series(3, SocialSeriesGenerator::MODE_AI, ['bluesky', 'linkedin'], 'Une photo par jour'), $moments[0]);

        $this->assertSame(['Un 300', 'Deux 300'], array_map(static fn (SocialPost $post): ?string => $post->getText(), $posts));
    }

    // One text with sources picked: each draft takes the next photograph's picture and title under that text, and fewer photographs left make fewer drafts
    public function testOneTextWithSourcesTakesAPictureForEachDraft(): void
    {
        $moments = $this->generator()->moments(new \DateTimeImmutable('2026-11-01 09:00'), 3, 'days');

        $posts = $this->generator()->generate($this->series(3, SocialSeriesGenerator::MODE_TEXT, ['bluesky'], 'Photo du jour', ['gallery_media:3']), $moments[0]);

        $this->assertCount(2, $posts);
        $this->assertSame(['gallery_media', '34', 'Photo 34', 'https://example.org/34.jpg', 'Photo du jour'], [$posts[1]->getSourceType(), $posts[1]->getSourceId(), $posts[1]->getTitle(), $posts[1]->getThumbnailUrl(), $posts[1]->getText()]);
        $this->assertTrue($posts[1]->hasOwnText());
    }

    // The site's contents are prepared one per moment, among the sources picked
    public function testTheSourcesPrepareOneDraftPerMoment(): void
    {
        $moments = $this->generator()->moments(new \DateTimeImmutable('2026-11-01 09:00'), 2, 'days', 7);
        $generator = $this->generator();

        $generator->generate($this->series(2, SocialSeriesGenerator::MODE_SOURCE, ['bluesky'], '', ['gallery_media:3'], 'days', 7), $moments[0]);

        $this->assertEquals([[$moments, ['gallery_media:3']]], $this->drafted);
    }

    // No draft made, as when the sources ran out, keeps neither the series nor its media
    public function testASeriesWithoutDraftsKeepsNothing(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'series');
        imagejpeg(imagecreatetruecolor(40, 30), $path);
        $series = $this->series(2, SocialSeriesGenerator::MODE_SOURCE, ['bluesky'], '', ['gallery_media']);

        $this->assertSame([], $this->generator()->generate($series, new \DateTimeImmutable('2026-11-01 09:00'), new File($path)));
        $this->assertFileDoesNotExist(sys_get_temp_dir() . '/public/' . $series->getMedia()[0]);
    }

    // Prolonged, the series goes on from the moment after its last post at its own pace, as many drafts again
    public function testAProlongationGoesOnFromTheLastPost(): void
    {
        $last = new \DateTimeImmutable('2026-11-07 21:00');

        $everyOtherDay = $this->generator(lastPlannedAt: $last)->prolong($this->series(2, SocialSeriesGenerator::MODE_TEXT, ['bluesky'], 'Soir', interval: 2));
        $this->assertEquals([new \DateTimeImmutable('2026-11-09 21:00'), new \DateTimeImmutable('2026-11-11 21:00')], array_map(static fn (SocialPost $post): \DateTimeImmutable => $post->getPlannedAt(), $everyOtherDay));

        // A Saturday's last post on Mondays and Wednesdays goes on the Monday after
        $mondays = $this->generator(lastPlannedAt: $last)->prolong($this->series(1, SocialSeriesGenerator::MODE_TEXT, ['bluesky'], 'Soir', frequency: 'weekdays', weekdays: [1, 3]));
        $this->assertEquals(new \DateTimeImmutable('2026-11-09 21:00'), $mondays[0]->getPlannedAt());
    }

    // A series whose posts were all deleted has no last post to go on from
    public function testASeriesWithoutPostsIsNotProlonged(): void
    {
        $this->assertSame([], $this->generator()->prolong($this->series(3, SocialSeriesGenerator::MODE_TEXT, ['bluesky'], 'Soir')));
    }
}
