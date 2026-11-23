<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Command;

use c975L\SocialBundle\Service\SocialSlotPlan;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// The plan of the coming days up to a date (SocialSlotPlan): each moment of the template week with its post or left empty, the posts off the slots, how many go out each day and what a network would refuse, then the series drawing from the contents and whether they run out - read only, for the monthly check before the drafts are approved
#[AsCommand(
    name: 'c975l:social:plan',
    description: 'Shows the coming moments of the template week up to a date, each with its post or left empty',
)]
class PlanCommand extends Command
{
    public function __construct(
        private readonly SocialSlotPlan $slotPlan,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('until', null, InputOption::VALUE_REQUIRED, 'The last day read, written YYYY-MM-DD')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Write the plan as JSON, for a script')
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

        $plan = $this->slotPlan->read($day);
        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($plan, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $io->table(['Moment', 'Slot', 'Post', 'State', 'Day', 'Problems'], array_map(static fn (array $row): array => [
            $row['at'],
            $row['slot']['label'] ?? '',
            null === $row['post'] ? '(empty)' : mb_strimwidth((string) $row['post']['title'], 0, 50, '…'),
            $row['post']['state'] ?? '',
            $row['dayCount'],
            implode(' / ', $row['post']['problems'] ?? []),
        ], $plan['moments']));
        foreach ($plan['series'] as $series) {
            $line = sprintf('%s: %d slot(s) to fill, %d content(s) free', $series['title'], $series['toFill'], $series['free']);
            $series['runsOut'] ? $io->warning($line) : $io->writeln($line);
        }

        return Command::SUCCESS;
    }
}
