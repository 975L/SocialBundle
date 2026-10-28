<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Command;

use c975L\SocialBundle\Controller\Management\SocialConnectionsController;
use c975L\SocialBundle\Service\BlueskyOAuthClient;
use c975L\SocialBundle\Service\SocialAdminMailer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

// A connection made before BlueskyOAuthClient::SCOPE asked for more keeps the permissions first granted, Bluesky then refusing what needs the new ones (a video upload): "email-to" is told once per SCOPE, the tile saying so until the account is connected again. Scheduled nightly (see SocialMaintenanceTaskProvider)
#[AsCommand(
    name: 'c975l:social:bluesky:reminder',
    description: 'Emails a reminder to connect Bluesky again when its permissions are out of date',
)]
class BlueskyReminderCommand extends Command
{
    public function __construct(
        private readonly BlueskyOAuthClient $blueskyOAuthClient,
        private readonly SocialAdminMailer $mailer,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->blueskyOAuthClient->needsReconnection() || $this->blueskyOAuthClient->isReconnectionNotified()) {
            $io->success('No Bluesky reminder due.');

            return Command::SUCCESS;
        }

        $error = $this->mailer->send(
            $this->translator->trans('email.social_bluesky_reminder_subject', [], 'social'),
            $this->translator->trans('email.social_bluesky_reminder_body', [], 'social') . "\n\n" . $this->mailer->url(SocialConnectionsController::ROUTE),
        );
        // A warning as the other announcements give, an error sending a CRITICAL alert - and not marked, so the next night tries again
        if (null !== $error) {
            $io->warning(sprintf('The reminder was not sent: %s', $error));

            return Command::SUCCESS;
        }

        $this->blueskyOAuthClient->markReconnectionNotified();
        $io->success('Reminder sent: Bluesky has to be connected again.');

        return Command::SUCCESS;
    }
}
