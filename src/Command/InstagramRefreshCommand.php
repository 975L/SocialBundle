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
use c975L\SocialBundle\Service\InstagramLoginClient;
use c975L\SocialBundle\Service\SocialAdminMailer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

// The token of an Instagram connected with no Facebook Page lasts 60 days and can only be renewed before that end: renewed nightly once it nears it, the connection never lapses, even on a site posting nothing for months. A renewal Instagram refuses is mailed to "email-to", the account having to connect again - the first night it fails and 3 days before the token ends, the nights between only warning. Scheduled nightly (see SocialMaintenanceTaskProvider)
#[AsCommand(
    name: 'c975l:social:instagram:refresh',
    description: 'Renews the Instagram token before it expires',
)]
class InstagramRefreshCommand extends Command
{
    use DaysLeftTrait;

    // The days left on the token on which a failure is mailed again
    public const int LAST_REMINDER = 3;

    public function __construct(
        private readonly InstagramLoginClient $instagramLoginClient,
        private readonly SocialAdminMailer $mailer,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->instagramLoginClient->isConfigured() || !$this->instagramLoginClient->isConnected() || !$this->instagramLoginClient->needsRefresh()) {
            $io->success('No Instagram renewal due.');

            return Command::SUCCESS;
        }

        try {
            $this->instagramLoginClient->refresh();
        } catch (\RuntimeException $exception) {
            if (!$this->isMailDue()) {
                $io->warning(sprintf('Instagram refused the renewal: %s', $exception->getMessage()));

                return Command::SUCCESS;
            }

            $error = $this->mailer->send(
                $this->translator->trans('email.social_instagram_refresh_failed_subject', [], 'social'),
                $this->translator->trans('email.social_instagram_refresh_failed_body', ['%error%' => $exception->getMessage()], 'social') . "\n\n" . $this->mailer->url(SocialConnectionsController::ROUTE),
            );
            // A warning as the reminders give, an error sending a CRITICAL alert
            $io->warning(sprintf('Instagram refused the renewal: %s%s', $exception->getMessage(), null === $error ? '' : sprintf(' (and the email was not sent: %s)', $error)));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Instagram token renewed until %s.', $this->instagramLoginClient->expiresAt()?->format('d/m/Y')));

        return Command::SUCCESS;
    }

    // The first failing night - the token entered the renewal window within the last day - or LAST_REMINDER days before it ends
    private function isMailDue(): bool
    {
        $expiresAt = $this->instagramLoginClient->expiresAt();
        $firstNight = null !== $expiresAt && $expiresAt > new \DateTimeImmutable(InstagramLoginClient::REFRESH_BEFORE)->modify('-1 day');

        return $firstNight || self::LAST_REMINDER === $this->daysLeft($expiresAt);
    }
}
