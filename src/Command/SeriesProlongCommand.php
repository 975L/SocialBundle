<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Command;

use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Entity\SocialSlot;
use c975L\SocialBundle\Repository\SocialSeriesRepository;
use c975L\SocialBundle\Repository\SocialSlotRepository;
use c975L\SocialBundle\Service\SocialSeriesGenerator;
use c975L\SocialBundle\Service\SocialSeriesProlonger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// "Prolong the series" as many times as it takes for a series to reach a date, each time as the button does (SocialSeriesProlonger, SocialSeriesGenerator::prolong()) - the running series and those tied to a slot, or the ones named. A series already reaching the date is left as it is, so the command runs again harmlessly; an imported series is never prolonged, its next posts being imported. The drafts wait on the calendar to be read and approved
#[AsCommand(
    name: 'c975l:social:series:prolong',
    description: 'Prolongs the social series until a date, as drafts waiting for approval',
)]
class SeriesProlongCommand extends Command
{
    public function __construct(
        private readonly SocialSeriesRepository $seriesRepository,
        private readonly SocialSeriesProlonger $prolonger,
        private readonly SocialSlotRepository $slotRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('series', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'The ids of the series to prolong, by default the running ones (last post still to come) and those tied to a slot of the template week')
            ->addOption('until', null, InputOption::VALUE_REQUIRED, 'The day the series are prolonged to, written YYYY-MM-DD')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the series, the drafts a prolongation would make and where they would end, without writing anything')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $input->getOption('until'));
        if (false === $day || $day->format('Y-m-d') !== $input->getOption('until')) {
            $io->error('--until is a day written YYYY-MM-DD.');

            return Command::FAILURE;
        }
        $until = $day->setTime(23, 59, 59);

        $ids = array_map(intval(...), (array) $input->getArgument('series'));
        $rows = $this->selected($ids);
        $left = array_diff($ids, array_map(static fn (array $row): ?int => $row['series']->getId(), $rows));
        if ([] !== $left) {
            $io->warning(sprintf('Series %s not found or imported, left out.', implode(', ', $left)));
        }
        if ([] === $rows) {
            $io->success('No series to prolong.');

            return Command::SUCCESS;
        }

        $lines = [];
        $short = [];
        $skipped = [];
        foreach ($rows as $row) {
            [$made, $last, $cut, $held] = $this->prolonger->until($row['series'], $row['lastPlannedAt'], $until, (bool) $input->getOption('dry-run'));
            foreach ($held as $minute => $title) {
                $skipped[] = sprintf('Series %d: %s passed over, already held by "%s".', $row['series']->getId(), $minute, $title);
            }
            $lines[] = [$row['series']->getId(), $row['series']->getTitle(), $row['lastPlannedAt']->format('d/m/Y H:i'), $made, $last->format('d/m/Y H:i')];
            if ($cut) {
                $short[] = sprintf('Series %d stopped at %s, short of --until: run the command again.', $row['series']->getId(), $last->format('d/m/Y H:i'));
            }
        }
        $io->table(['Id', 'Series', 'Last post', 'Drafts made', 'Now ends'], $lines);
        if ([] !== $short) {
            $io->warning($short);
        }
        // A minute of a slot another post holds is passed over rather than refused: the prolongation goes on, the run staying a success
        if ([] !== $skipped) {
            $io->note($skipped);
        }

        if ($input->getOption('dry-run')) {
            $io->note('Dry run: nothing written, the drafts counted as the moments to come (the AI or the sources may make fewer).');
        }

        return Command::SUCCESS;
    }

    // The series named, or the ones still running and those tied to a slot of the template week even once ended, imported ones left out
    /**
     * @param list<int> $ids
     *
     * @return array<int, array{series: SocialSeries, lastPlannedAt: \DateTimeImmutable}>
     */
    private function selected(array $ids): array
    {
        $onSlots = array_map(static fn (SocialSlot $slot): ?int => $slot->getSeries()?->getId(), $this->slotRepository->findOrdered());

        return array_filter(
            $this->seriesRepository->findWithLastPlannedAt(),
            static fn (array $row): bool => SocialSeriesGenerator::MODE_IMPORT !== $row['series']->getMode()
                && ([] === $ids ? $row['lastPlannedAt'] > new \DateTimeImmutable() || \in_array($row['series']->getId(), $onSlots, true) : \in_array($row['series']->getId(), $ids, true)),
        );
    }
}
