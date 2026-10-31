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
use c975L\SocialBundle\Repository\SocialSeriesRepository;
use c975L\SocialBundle\Service\SocialSeriesGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// "Prolong the series" as many times as it takes for a series to reach a date, each time as the button does (SocialSeriesGenerator::prolong()) - the running series, or the ones named. A series already reaching the date is left as it is, so the command runs again harmlessly; an imported series is never prolonged, its next posts being imported. The drafts wait on the calendar to be read and approved
#[AsCommand(
    name: 'c975l:social:series:prolong',
    description: 'Prolongs the social series until a date, as drafts waiting for approval',
)]
class SeriesProlongCommand extends Command
{
    // A guard against a series whose prolongation would never reach the date
    private const int MAX_ROUNDS = 100;

    public function __construct(
        private readonly SocialSeriesRepository $seriesRepository,
        private readonly SocialSeriesGenerator $seriesGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('series', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'The ids of the series to prolong, the running ones (last post still to come) by default')
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

        // The series named, or the ones still running, imported ones left out
        $ids = array_map(intval(...), (array) $input->getArgument('series'));
        $rows = array_filter(
            $this->seriesRepository->findWithLastPlannedAt(),
            static fn (array $row): bool => SocialSeriesGenerator::MODE_IMPORT !== $row['series']->getMode()
                && ([] === $ids ? $row['lastPlannedAt'] > new \DateTimeImmutable() : \in_array($row['series']->getId(), $ids, true)),
        );
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
        foreach ($rows as $row) {
            [$made, $last, $cut] = $this->prolong($row['series'], $row['lastPlannedAt'], $until, (bool) $input->getOption('dry-run'));
            $lines[] = [$row['series']->getId(), $row['series']->getTitle(), $row['lastPlannedAt']->format('d/m/Y H:i'), $made, $last->format('d/m/Y H:i')];
            if ($cut) {
                $short[] = sprintf('Series %d stopped at %s, short of --until: run the command again.', $row['series']->getId(), $last->format('d/m/Y H:i'));
            }
        }
        $io->table(['Id', 'Series', 'Last post', 'Drafts made', 'Now ends'], $lines);
        if ([] !== $short) {
            $io->warning($short);
        }

        if ($input->getOption('dry-run')) {
            $io->note('Dry run: nothing written, the drafts counted as the moments to come (the AI or the sources may make fewer).');
        }

        return Command::SUCCESS;
    }

    // Prolonged round after round until its last post reaches the day, none made past it - on a dry run, its moments only counted. The number of drafts, the new end, and whether the rounds ran out before the day returned
    /** @return array{int, \DateTimeImmutable, bool} */
    private function prolong(SocialSeries $series, \DateTimeImmutable $last, \DateTimeImmutable $until, bool $dryRun): array
    {
        $made = 0;
        for ($round = 0; $last < $until && $round < self::MAX_ROUNDS; ++$round) {
            if ($dryRun) {
                $new = $this->seriesGenerator->prolongMoments($series, $last, $until);
                $next = [] === $new ? null : end($new);
            } else {
                $new = $this->seriesGenerator->prolong($series, $until);
                $next = [] === $new ? null : $this->seriesRepository->findLastPlannedAt($series) ?? $until;
            }
            if (null === $next) {
                break;
            }
            $made += \count($new);
            $last = $next;
        }

        return [$made, $last, self::MAX_ROUNDS === $round && $last < $until];
    }
}
