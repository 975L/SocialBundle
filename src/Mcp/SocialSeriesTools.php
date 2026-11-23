<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Mcp;

use c975L\ConfigBundle\Mcp\McpTool;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Entity\SocialSlot;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Repository\SocialSeriesRepository;
use c975L\SocialBundle\Repository\SocialSlotRepository;
use c975L\SocialBundle\Service\SocialSeriesGenerator;
use c975L\SocialBundle\Service\SocialSeriesProlonger;
use Mcp\Server\Exception\ToolCallException;
use Psr\Clock\ClockInterface;

// The series on the site's MCP endpoint, as on their page of the calendar (see SocialCalendarController::series()): listed, read with their posts to come, prolonged until a day as c975l:social:series:prolong does - approving, deleting and their texts left to the back office
class SocialSeriesTools
{
    // Days ahead an agent prolongs a series at most: each post of a series written by the AI is a paid call
    public const int MAX_DAYS = 92;

    public function __construct(
        private readonly SocialSeriesRepository $seriesRepository,
        private readonly SocialPostRepository $postRepository,
        private readonly SocialSlotRepository $slotRepository,
        private readonly SocialSeriesProlonger $prolonger,
        private readonly SocialMcpAccess $access,
        private readonly ClockInterface $clock,
    ) {
    }

    // The tools of the series, under the social key of SocialMcpProvider
    /** @return iterable<McpTool> */
    public function getMcpTools(): iterable
    {
        yield new McpTool('social_series', 'Social series', 'The series, the one ending first first: mode, pace, networks, how many posts are to come and how many of them are drafts, the moment of the last post and the slots it is prolonged on.', $this->list(...));
        yield new McpTool('social_series_get', 'One social series', 'A series with its settings and its posts to come, each with its moment and state.', $this->get(...));
        yield new McpTool('social_series_prolong', 'Prolong a series', 'Prolongs a series until a day as the back office does, on its slots or at its pace: only drafts are made, a human approves them; a minute another post holds is passed over and said. An imported or ended series is refused, its next posts being imported or the back office prolonging it.', $this->prolong(...), true);
    }

    // Every series holding posts, the one ending first first, each with its counts and its slots - four queries whatever their number
    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $this->access->guard();
        $counts = $this->postRepository->countComingBySeries($this->clock->now());
        $slots = [];
        foreach ($this->slotRepository->findOrdered() as $slot) {
            if (null !== $slot->getSeries()) {
                $slots[(int) $slot->getSeries()->getId()][] = $slot->getId();
            }
        }

        return array_map(fn (array $row): array => $this->summary(
            $row['series'],
            $row['lastPlannedAt'],
            $counts[(int) $row['series']->getId()] ?? ['coming' => 0, 'drafts' => 0],
            $slots[(int) $row['series']->getId()] ?? [],
        ), $this->seriesRepository->findWithLastPlannedAt());
    }

    // A series with its settings and its posts to come in their order, an imported one read as well
    /**
     * @param int $series id of the series
     *
     * @return array<string, mixed>
     */
    public function get(int $series): array
    {
        $this->access->guard();
        $found = $this->found($series);
        $now = $this->clock->now();
        $posts = $this->postRepository->findComingBySeries($found, $now);
        $counts = ['coming' => \count($posts), 'drafts' => $this->postRepository->countComingDraftsBySeries($found, $now)];
        $slots = array_map(static fn (SocialSlot $slot): ?int => $slot->getId(), $this->slotRepository->findBySeries($found));

        return [
            ...$this->summary($found, $this->seriesRepository->findLastPlannedAt($found), $counts, $slots),
            'count' => $found->getCount(),
            'text' => $found->getText(),
            'sources' => $found->getSources(),
            'story' => $found->isStory(),
            'posts' => array_map(static fn (SocialPost $post): array => [
                'id' => $post->getId(),
                'planned' => $post->getPlannedAt()->format('Y-m-d H:i'),
                'state' => $post->getState(),
            ], $posts),
        ];
    }

    // A series prolonged until the day given, as c975l:social:series:prolong does: drafts only, the minutes another post holds passed over - an imported series, an ended one, or one without any post left, refused
    /**
     * @param int    $series id of the series
     * @param string $until  day the series is prolonged to, YYYY-MM-DD, its posts of that day included
     *
     * @return array{series: int, made: int, last: string, short: bool, passed_over: array<string, string>}
     */
    public function prolong(int $series, string $until): array
    {
        $this->access->guard();
        $day = $this->access->day($until);
        if (null === $day) {
            throw new ToolCallException('The day the series is prolonged to is written YYYY-MM-DD.');
        }
        if ($day > $this->clock->now()->setTime(0, 0)->modify('+' . self::MAX_DAYS . ' days')) {
            throw new ToolCallException(sprintf('A series is prolonged %d days ahead at most from here; further on, from the back office.', self::MAX_DAYS));
        }
        $found = $this->found($series);
        if (SocialSeriesGenerator::MODE_IMPORT === $found->getMode()) {
            throw new ToolCallException(sprintf('The series %d is imported from a file: its next posts are imported, never prolonged.', $series));
        }
        $last = $this->seriesRepository->findLastPlannedAt($found);
        if (null === $last) {
            throw new ToolCallException(sprintf('The series %d has no post left to prolong it from.', $series));
        }
        if ($last <= $this->clock->now()) {
            throw new ToolCallException(sprintf('The series %d has ended: an ended series is prolonged from the back office.', $series));
        }

        [$made, $end, $short, $skipped] = $this->prolonger->until($found, $last, $day->setTime(23, 59, 59));

        return ['series' => $series, 'made' => $made, 'last' => $end->format('Y-m-d H:i'), 'short' => $short, 'passed_over' => $skipped];
    }

    // A series by its id
    private function found(int $id): SocialSeries
    {
        $series = $this->seriesRepository->find($id);
        if (!$series instanceof SocialSeries) {
            throw new ToolCallException(sprintf('No series %d.', $id));
        }

        return $series;
    }

    // A series as the tools give it: its settings, its counts, its last moment and the slots it is prolonged on
    /**
     * @param array{coming: int, drafts: int} $counts
     * @param list<?int>                      $slots
     *
     * @return array<string, mixed>
     */
    private function summary(SocialSeries $series, ?\DateTimeImmutable $last, array $counts, array $slots): array
    {
        return [
            'id' => $series->getId(),
            'title' => $series->getTitle(),
            'mode' => $series->getMode(),
            'pace' => ['frequency' => $series->getFrequency(), 'interval' => $series->getInterval(), 'weekdays' => $series->getWeekdays()],
            'networks' => $series->getNetworks(),
            'coming' => $counts['coming'],
            'drafts' => $counts['drafts'],
            'last' => $last?->format('Y-m-d H:i'),
            'slots' => $slots,
        ];
    }
}
