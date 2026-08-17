<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Command;

use c975L\SocialBundle\Service\ReviewSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// Imports the reviews every configured source exposes, "--source=google" restricting the run to that one. Meant for cron rather than for a page render: platform quotas are counted per call, and a site must keep serving its reviews while they are down
#[AsCommand(
    name: 'c975l:social:reviews:sync',
    description: 'Imports the reviews of every configured source',
)]
class ReviewsSyncCommand extends Command
{
    public function __construct(private readonly ReviewSynchronizer $reviewSynchronizer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('source', null, InputOption::VALUE_REQUIRED, 'Restrict the run to one source, by name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = $input->getOption('source');

        try {
            $imported = $this->reviewSynchronizer->synchronize(is_string($source) ? $source : null);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        // An empty result is the normal state of a site that configured no source yet, not a failure worth a non-zero exit in a cron
        if ([] === $imported) {
            $io->warning('No configured review source to synchronize.');

            return Command::SUCCESS;
        }

        foreach ($imported as $name => $count) {
            $io->success(sprintf('%s: %d review(s) synchronized.', $name, $count));
        }

        return Command::SUCCESS;
    }
}
