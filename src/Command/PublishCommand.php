<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Command;

use c975L\SocialBundle\Service\SocialPublisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// Prepares the next post once the configured interval has passed, and sends it at once on the networks set to publish automatically - scheduled hourly (see SocialMaintenanceTaskProvider), the interval being the site's to set rather than the schedule's. "--url" prepares a post of any page instead, "--force" ignores the interval and the on/off switch, "--dry-run" prints what each network would receive and sends nothing - the JPEG copy Meta would download is written, being the one the post will use
#[AsCommand(
    name: 'c975l:social:publish',
    description: 'Prepares the next post for the social networks, and sends it where they publish automatically',
)]
class PublishCommand extends Command
{
    public function __construct(private readonly SocialPublisher $socialPublisher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Prepare a post of this page, read from its Open Graph tags')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Prepare now, whatever the interval and the on/off switch')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what each network would receive, without sending anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $url = $input->getOption('url');
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $report = \is_string($url)
                ? $this->socialPublisher->prepareUrl($url, $dryRun)
                : $this->socialPublisher->prepareNext((bool) $input->getOption('force'), $dryRun);
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
}
