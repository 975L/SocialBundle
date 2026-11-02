<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Command;

use c975L\SocialBundle\Service\LinkedInClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// A Page app's access token lasts 60 days and its refresh token a year: renewed nightly once the access token is within LinkedInClient::REFRESH_BEFORE of its end. A refusal only warns, c975l:social:linkedin:reminder mailing "email-to" 14 days before the token ends, then 3. A profile app holds no refresh token, and is stepped over. Scheduled nightly (see SocialMaintenanceTaskProvider)
#[AsCommand(
    name: 'c975l:social:linkedin:refresh',
    description: 'Renews the LinkedIn token of a Page app before it expires',
)]
class LinkedInRefreshCommand extends Command
{
    public function __construct(
        private readonly LinkedInClient $linkedInClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Renew now, however far the end of the token');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->linkedInClient->isConfigured() || !$this->linkedInClient->canRefresh() || (!$input->getOption('force') && !$this->linkedInClient->needsRefresh())) {
            $io->success('No LinkedIn refresh due.');

            return Command::SUCCESS;
        }

        try {
            $this->linkedInClient->refresh();
        } catch (\RuntimeException $exception) {
            // A warning as the reminders give, an error sending a CRITICAL alert
            $io->warning($exception->getMessage());

            return Command::SUCCESS;
        }

        $io->success(sprintf('LinkedIn token renewed, until %s.', $this->linkedInClient->expiresAt()?->format('d/m/Y')));

        return Command::SUCCESS;
    }
}
