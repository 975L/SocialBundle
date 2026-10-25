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
use c975L\SocialBundle\Service\LinkedInClient;
use c975L\SocialBundle\Service\SocialAdminMailer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

// The LinkedIn token ends on its own after 60 days, LinkedIn handing a self-serve app no refresh token: "email-to" is told 14 days ahead, then 3, while reconnecting is one click - the health check only saying so on its own screen. Scheduled nightly (see SocialMaintenanceTaskProvider), one email on each of those days
#[AsCommand(
    name: 'c975l:social:linkedin:reminder',
    description: 'Emails a reminder to connect LinkedIn again before its token expires',
)]
class LinkedInReminderCommand extends Command
{
    // The days left on which the reminder goes
    public const array DAYS = [14, 3];

    public function __construct(
        private readonly LinkedInClient $linkedInClient,
        private readonly SocialAdminMailer $mailer,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Counted in calendar days of the server's timezone, whatever offset the end was stored with, so the nightly run meets each of them once
        $expiresAt = $this->linkedInClient->expiresAt();
        $today = new \DateTimeImmutable('today');
        $daysLeft = null === $expiresAt ? null : (int) $today->diff($expiresAt->setTimezone($today->getTimezone())->setTime(0, 0))->format('%r%a');
        if (!$this->linkedInClient->isConnected() || !\in_array($daysLeft, self::DAYS, true)) {
            $io->success('No LinkedIn reminder due.');

            return Command::SUCCESS;
        }

        $date = $expiresAt->format('d/m/Y');
        $error = $this->mailer->send(
            $this->translator->trans('email.social_linkedin_reminder_subject', ['%date%' => $date], 'social'),
            $this->translator->trans('email.social_linkedin_reminder_body', ['%date%' => $date], 'social') . "\n\n" . $this->mailer->url(SocialConnectionsController::ROUTE),
        );
        // A warning as the other announcements give, an error sending a CRITICAL alert
        if (null !== $error) {
            $io->warning(sprintf('The reminder was not sent: %s', $error));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Reminder sent: the LinkedIn token expires on %s.', $date));

        return Command::SUCCESS;
    }
}
