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
use c975L\SocialBundle\Service\SocialAdminMailer;
use c975L\SocialBundle\Service\TikTokClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

// TikTok's access token lasts 24 hours and its refresh token a year, a refresh possibly handing a new one: refreshed every night, the connection never lapses, even on a site posting nothing for months. A refresh TikTok refuses is mailed to "email-to", the account having to connect again - the first night it fails and 3 days before the connection ends, the nights between only warning. Scheduled nightly (see SocialMaintenanceTaskProvider)
#[AsCommand(
    name: 'c975l:social:tiktok:refresh',
    description: 'Renews the TikTok tokens before they expire',
)]
class TikTokRefreshCommand extends Command
{
    use DaysLeftTrait;

    // The days left on the refresh token on which a failure is mailed again
    public const int LAST_REMINDER = 3;

    public function __construct(
        private readonly TikTokClient $tikTokClient,
        private readonly SocialAdminMailer $mailer,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->tikTokClient->isConfigured() || !$this->tikTokClient->isConnected()) {
            $io->success('No TikTok refresh due.');

            return Command::SUCCESS;
        }

        // Read before the refresh, which writes a new end when it works
        $expiresAt = $this->tikTokClient->expiresAt();
        try {
            $this->tikTokClient->refresh();
        } catch (\RuntimeException $exception) {
            if (!$this->isMailDue($expiresAt)) {
                $io->warning(sprintf('TikTok refused the refresh: %s', $exception->getMessage()));

                return Command::SUCCESS;
            }

            $error = $this->mailer->send(
                $this->translator->trans('email.social_tiktok_refresh_failed_subject', [], 'social'),
                $this->translator->trans('email.social_tiktok_refresh_failed_body', ['%error%' => $exception->getMessage()], 'social') . "\n\n" . $this->mailer->url(SocialConnectionsController::ROUTE),
            );
            // A warning as the reminders give, an error sending a CRITICAL alert
            $io->warning(sprintf('TikTok refused the refresh: %s%s', $exception->getMessage(), null === $error ? '' : sprintf(' (and the email was not sent: %s)', $error)));

            return Command::SUCCESS;
        }

        $io->success(sprintf('TikTok tokens renewed, the connection until %s.', $this->tikTokClient->refreshExpiresAt()?->format('d/m/Y')));

        return Command::SUCCESS;
    }

    // The first failing night - the access token, renewed every night, still valid or over for less than half a day, the next night's run then well past that whatever the scheduler's jitter - or LAST_REMINDER days before the refresh token ends
    private function isMailDue(?\DateTimeImmutable $expiresAt): bool
    {
        $firstNight = null !== $expiresAt && $expiresAt > new \DateTimeImmutable('-12 hours');

        return $firstNight || self::LAST_REMINDER === $this->daysLeft($this->tikTokClient->refreshExpiresAt());
    }
}
