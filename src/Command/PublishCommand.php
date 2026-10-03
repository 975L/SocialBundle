<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Command;

use c975L\SocialBundle\Repository\SocialScheduleRepository;
use c975L\SocialBundle\Service\SocialPublisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// Prepares the next post once the configured interval has passed, and sends it at once on the networks set to publish automatically - scheduled hourly (see SocialMaintenanceTaskProvider), the interval being the site's to set rather than the schedule's. "--url" prepares a post of any page instead, "--force" ignores the interval and the on/off switch, "--slot" runs a publication slot (see SocialSchedule), "--drafts=N" prepares N drafts to approve, "--planned" sends the approved posts whose planned moment has come, "--dry-run" prints what each network would receive and sends nothing - the JPEG copy Meta would download is written, being the one the post will use
#[AsCommand(
    name: 'c975l:social:publish',
    description: 'Prepares the next post for the social networks, and sends it where they publish automatically',
)]
class PublishCommand extends Command
{
    public function __construct(
        private readonly SocialPublisher $socialPublisher,
        private readonly SocialScheduleRepository $scheduleRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Prepare a post of this page, read from its Open Graph tags')
            ->addOption('slot', null, InputOption::VALUE_REQUIRED, 'Run this publication slot, by its id')
            ->addOption('planned', null, InputOption::VALUE_NONE, 'Send the approved posts whose planned moment has come')
            ->addOption('drafts', null, InputOption::VALUE_REQUIRED, 'Prepare this many posts as drafts to approve, sent nowhere')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Prepare now, whatever the interval and the on/off switch')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what each network would receive, without sending anything - the template\'s text, the AI never being called');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $url = $input->getOption('url');
        $slotId = $input->getOption('slot');
        $dryRun = (bool) $input->getOption('dry-run');

        // A batch to read and approve: what each one is, not what a network answered
        $drafts = $input->getOption('drafts');
        if (\is_string($drafts)) {
            if ($dryRun) {
                $io->error('--drafts prepares posts to approve: --dry-run has nothing to preview there.');

                return Command::INVALID;
            }

            $posts = $this->socialPublisher->prepareDrafts(max(1, (int) $drafts));
            foreach ($posts as $post) {
                $io->writeln(sprintf('#%d %s', (int) $post->getId(), $post->getTitle()));
            }
            $io->note(sprintf('%d draft(s) prepared.', \count($posts)));

            return Command::SUCCESS;
        }

        try {
            $report = match (true) {
                \is_string($url) => $this->socialPublisher->prepareUrl($url, $dryRun),
                \is_string($slotId) => $this->prepareSlot((int) $slotId, $dryRun),
                (bool) $input->getOption('planned') => $this->socialPublisher->publishPlanned($dryRun),
                default => $this->socialPublisher->prepareNext((bool) $input->getOption('force'), $dryRun),
            };
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        // Nothing due, no network configured or nothing left to post: the normal state of most hourly runs, not a failure
        if ([] === $report) {
            $io->note('Nothing prepared.');

            return Command::SUCCESS;
        }

        foreach ($report as $network => $result) {
            if (isset($result['payload'])) {
                $io->section(sprintf('%s (%s)', $network, $result['message']));
                $io->writeln((string) json_encode($result['payload'], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
                continue;
            }

            $io->writeln(sprintf('%s: %s %s', $network, $result['status'], $result['message']));
        }

        // A refused post is kept, failed, on the screen where it is published again - the run did its job, so a cron has nothing to be woken for
        return Command::SUCCESS;
    }

    // A slot disabled or deleted since the worker read the schedule prepares nothing: its task only goes away with the next start of the worker
    /** @return array<string, array<string, mixed>> */
    private function prepareSlot(int $slotId, bool $dryRun): array
    {
        $slot = $this->scheduleRepository->find($slotId);
        if (null === $slot || (!$slot->isEnabled() && !$dryRun)) {
            return [];
        }

        return $this->socialPublisher->prepareSlot($slot, $dryRun);
    }
}
