<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Command;

use c975L\SocialBundle\Service\SocialPlanner;
use c975L\SocialBundle\Service\SocialPublisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// Sends the approved posts whose planned moment has come - scheduled every quarter of an hour (see SocialMaintenanceTaskProvider). "--url" prepares a draft of any page instead, planned at "--at" or the next quarter of an hour, "--dry-run" prints what each network would receive and sends nothing - the JPEG copy Meta would download is written, being the one the post will use
#[AsCommand(
    name: 'c975l:social:publish',
    description: 'Sends the approved social network posts whose planned moment has come',
)]
class PublishCommand extends Command
{
    public function __construct(
        private readonly SocialPublisher $socialPublisher,
        private readonly SocialPlanner $planner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Prepare a draft of this page, read from its Open Graph tags')
            ->addOption('at', null, InputOption::VALUE_REQUIRED, 'The moment the draft of "--url" is planned at, the next quarter of an hour by default')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what each network would receive, without sending anything - the template\'s text, the AI never being called');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $url = $input->getOption('url');
        $at = $input->getOption('at');
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $report = \is_string($url)
                ? $this->socialPublisher->prepareUrl($url, $this->planner->round(\is_string($at) ? new \DateTimeImmutable($at) : $this->planner->nextQuarter(new \DateTimeImmutable())), $dryRun)
                : $this->socialPublisher->publishPlanned($dryRun);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        // Nothing due, or no network configured: the normal state of most runs, not a failure
        if ([] === $report) {
            $io->note('Nothing to send.');

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
}
