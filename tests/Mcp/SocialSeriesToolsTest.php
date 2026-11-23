<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Mcp;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Entity\SocialSlot;
use c975L\SocialBundle\Mcp\SocialMcpAccess;
use c975L\SocialBundle\Mcp\SocialSeriesTools;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Repository\SocialSeriesRepository;
use c975L\SocialBundle\Repository\SocialSlotRepository;
use c975L\SocialBundle\Service\SocialMediaFile;
use c975L\SocialBundle\Service\SocialPostWriter;
use c975L\SocialBundle\Service\SocialPublisher;
use c975L\SocialBundle\Service\SocialSeriesGenerator;
use c975L\SocialBundle\Service\SocialSeriesProlonger;
use c975L\SocialBundle\Service\SocialSlotCalendar;
use c975L\SocialBundle\Service\SocialSlotFinder;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Server\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;

class SocialSeriesToolsTest extends TestCase
{
    // The series listed with their pace, their counts by series and the slots they are prolonged on, an imported one among them
    public function testList(): void
    {
        $series = $this->series(4);
        $imported = $this->series(5, SocialSeriesGenerator::MODE_IMPORT);
        $seriesRepository = $this->createStub(SocialSeriesRepository::class);
        $seriesRepository->method('findWithLastPlannedAt')->willReturn([
            ['series' => $series, 'lastPlannedAt' => new \DateTimeImmutable('2026-12-04 21:00')],
            ['series' => $imported, 'lastPlannedAt' => new \DateTimeImmutable('2026-12-11 09:00')],
        ]);
        $postRepository = $this->createStub(SocialPostRepository::class);
        $postRepository->method('countComingBySeries')->willReturn([4 => ['coming' => 3, 'drafts' => 2]]);
        $slot = new SocialSlot()->setLabel('item-1')->setSeries($series);
        new \ReflectionProperty(SocialSlot::class, 'id')->setValue($slot, 7);
        $slotRepository = $this->createStub(SocialSlotRepository::class);
        $slotRepository->method('findOrdered')->willReturn([$slot, new SocialSlot()->setLabel('item-2')]);

        $list = $this->tools($seriesRepository, $postRepository, $slotRepository)->list();

        $this->assertSame([4, 5], array_column($list, 'id'));
        $this->assertSame(['frequency' => 'days', 'interval' => 7, 'weekdays' => []], $list[0]['pace']);
        $this->assertSame([3, 2, '2026-12-04 21:00', [7]], [$list[0]['coming'], $list[0]['drafts'], $list[0]['last'], $list[0]['slots']]);
        $this->assertSame([0, 0, [], SocialSeriesGenerator::MODE_IMPORT], [$list[1]['coming'], $list[1]['drafts'], $list[1]['slots'], $list[1]['mode']]);
    }

    // A series read with its settings and its posts to come, each with its moment and state
    public function testGet(): void
    {
        $series = $this->series(4);
        $seriesRepository = $this->createStub(SocialSeriesRepository::class);
        $seriesRepository->method('find')->willReturn($series);
        $seriesRepository->method('findLastPlannedAt')->willReturn(new \DateTimeImmutable('2026-12-04 21:00'));
        $post = new SocialPost(SocialPost::SOURCE_MANUAL, 'item-1', 'item-1', '', null, new \DateTimeImmutable('2026-11-27 21:00'));
        new SocialPostTarget($post, 'catalog', 'Text');
        new \ReflectionProperty(SocialPost::class, 'id')->setValue($post, 12);
        $postRepository = $this->createStub(SocialPostRepository::class);
        $postRepository->method('findComingBySeries')->willReturn([$post]);
        $postRepository->method('countComingDraftsBySeries')->willReturn(1);

        $read = $this->tools($seriesRepository, $postRepository)->get(4);

        $this->assertSame(['catalog', 1, 1, '2026-12-04 21:00', 'Text'], [$read['title'], $read['coming'], $read['drafts'], $read['last'], $read['text']]);
        $this->assertSame([['id' => 12, 'planned' => '2026-11-27 21:00', 'state' => 'draft']], $read['posts']);
    }

    // Through the real generator, a series prolonged until a day gains drafts only, none past the day, and says where it now ends
    public function testProlongMakesDraftsOnly(): void
    {
        $series = $this->series(4);
        $last = new \DateTimeImmutable('2026-11-24 21:00');
        $persisted = [];
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted, &$last): void {
            if ($entity instanceof SocialPost) {
                $persisted[] = $entity;
                $last = max($last, $entity->getPlannedAt());
            }
        });
        $seriesRepository = $this->createStub(SocialSeriesRepository::class);
        $seriesRepository->method('find')->willReturn($series);
        $seriesRepository->method('findLastPlannedAt')->willReturnCallback(static function () use (&$last): \DateTimeImmutable {
            return $last;
        });
        $publisher = $this->createStub(SocialPublisher::class);
        $publisher->method('createManual')->willReturnCallback(static function (\DateTimeImmutable $at): SocialPost {
            $post = new SocialPost(SocialPost::SOURCE_MANUAL, 'item-1', '', '', null, $at);
            new SocialPostTarget($post, 'catalog', '');

            return $post;
        });
        $generator = new SocialSeriesGenerator($publisher, $this->createStub(SocialPostWriter::class), new SocialMediaFile(), $entityManager, $seriesRepository, $this->createStub(SocialSlotRepository::class), new SocialSlotFinder(new SocialSlotCalendar(), $this->createStub(SocialPostRepository::class)), sys_get_temp_dir());

        $prolonged = $this->tools($seriesRepository, prolonger: new SocialSeriesProlonger($seriesRepository, $generator))->prolong(4, '2026-12-14');

        $this->assertSame(['series' => 4, 'made' => 2, 'last' => '2026-12-08 21:00', 'short' => false, 'passed_over' => []], $prolonged);
        $this->assertSame(['draft', 'draft'], array_map(static fn (SocialPost $post): string => $post->getState(), $persisted));
        $this->assertSame([$series, $series], array_map(static fn (SocialPost $post): ?SocialSeries => $post->getSeries(), $persisted));
    }

    // An imported series is refused, its next posts being imported, and nothing is prolonged
    public function testProlongRefusesAnImportedSeries(): void
    {
        $seriesRepository = $this->createStub(SocialSeriesRepository::class);
        $seriesRepository->method('find')->willReturn($this->series(5, SocialSeriesGenerator::MODE_IMPORT));
        $prolonger = $this->createMock(SocialSeriesProlonger::class);
        $prolonger->expects($this->never())->method('until');

        $this->expectExceptionMessage('imported from a file');
        $this->tools($seriesRepository, prolonger: $prolonger)->prolong(5, '2026-12-14');
    }

    // A series whose posts were all deleted has nothing to be prolonged from
    public function testProlongRefusesASeriesWithoutPosts(): void
    {
        $seriesRepository = $this->createStub(SocialSeriesRepository::class);
        $seriesRepository->method('find')->willReturn($this->series(4));

        $this->expectExceptionMessage('no post left');
        $this->tools($seriesRepository)->prolong(4, '2026-12-14');
    }

    // An ended series is refused, an agent never prolonging from a last post gone by, and nothing is prolonged
    public function testProlongRefusesAnEndedSeries(): void
    {
        $seriesRepository = $this->createStub(SocialSeriesRepository::class);
        $seriesRepository->method('find')->willReturn($this->series(4));
        $seriesRepository->method('findLastPlannedAt')->willReturn(new \DateTimeImmutable('2026-11-01 21:00'));
        $prolonger = $this->createMock(SocialSeriesProlonger::class);
        $prolonger->expects($this->never())->method('until');

        $this->expectExceptionMessage('has ended');
        $this->tools($seriesRepository, prolonger: $prolonger)->prolong(4, '2026-12-14');
    }

    // A day missing, or not written YYYY-MM-DD, is refused
    #[DataProvider('wrongDays')]
    public function testProlongRefusesAWrongDay(string $day): void
    {
        $seriesRepository = $this->createStub(SocialSeriesRepository::class);
        $seriesRepository->method('find')->willReturn($this->series(4));

        $this->expectException(ToolCallException::class);
        $this->tools($seriesRepository)->prolong(4, $day);
    }

    /** @return iterable<string, array{string}> */
    public static function wrongDays(): iterable
    {
        yield 'empty' => [''];
        yield 'rolled over' => ['2026-02-30'];
        yield 'words' => ['december'];
        yield 'too far' => ['2027-02-22'];
    }

    // A series asked by an id it does not have is refused, read or prolonged
    #[DataProvider('byId')]
    public function testAnUnknownSeriesIsRefused(string $tool): void
    {
        $this->expectExceptionMessage('No series 9.');
        $this->call($this->tools(), $tool, 9);
    }

    // An account that is no editor reaches none of the series' tools
    #[DataProvider('allTools')]
    public function testAnAccountNoEditorIsRefused(string $tool): void
    {
        $this->expectExceptionMessage('may not work on the social posts');
        $this->call($this->tools(granted: false), $tool, 4);
    }

    /** @return iterable<string, array{string}> */
    public static function byId(): iterable
    {
        yield 'get' => ['get'];
        yield 'prolong' => ['prolong'];
    }

    /** @return iterable<string, array{string}> */
    public static function allTools(): iterable
    {
        yield 'list' => ['list'];
        yield from self::byId();
    }

    // The tool named, called with the series given
    private function call(SocialSeriesTools $tools, string $tool, int $series): void
    {
        match ($tool) {
            'list' => $tools->list(),
            'get' => $tools->get($series),
            default => $tools->prolong($series, '2026-12-14'),
        };
    }

    // A weekly series in the mode given, its id given
    private function series(int $id, string $mode = SocialSeriesGenerator::MODE_TEXT): SocialSeries
    {
        $series = new SocialSeries('catalog', 2, 'days', 7, [], $mode, 'Text', [], ['catalog']);
        new \ReflectionProperty(SocialSeries::class, 'id')->setValue($series, $id);

        return $series;
    }

    // The tools on stubs, the account an editor unless said otherwise
    private function tools(?SocialSeriesRepository $series = null, ?SocialPostRepository $posts = null, ?SocialSlotRepository $slots = null, ?SocialSeriesProlonger $prolonger = null, bool $granted = true): SocialSeriesTools
    {
        $config = $this->createStub(ConfigServiceInterface::class);
        $config->method('get')->willReturn('ROLE_EDITOR');
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn($granted);

        return new SocialSeriesTools($series ?? $this->createStub(SocialSeriesRepository::class), $posts ?? $this->createStub(SocialPostRepository::class), $slots ?? $this->createStub(SocialSlotRepository::class), $prolonger ?? $this->createStub(SocialSeriesProlonger::class), new SocialMcpAccess($config, $security), new MockClock('2026-11-21 10:00'));
    }
}
