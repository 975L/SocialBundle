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
use c975L\SocialBundle\Service\PinterestClient;
use c975L\SocialBundle\Service\SocialAdminMailer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

// Pinterest's refresh token lasts 60 days and each refresh hands a new one: refreshed nightly once the access token nears its end, the connection never lapses, even on a site pinning nothing for months. A refresh Pinterest refuses is mailed to "email-to", the account having to connect again - the first night it fails and 3 days before the connection ends, the nights between only warning. Scheduled nightly (see SocialMaintenanceTaskProvider)
#[AsCommand(
    name: 'c975l:social:pinterest:refresh',
    description: 'Renews the Pinterest tokens before they expire',
)]
class PinterestRefreshCommand extends Command
{
    use DaysLeftTrait;

    // The days left on the refresh token on which a failure is mailed again
    public const int LAST_REMINDER = 3;

    public function __construct(
        private readonly PinterestClient $pinterestClient,
        private readonly SocialAdminMailer $mailer,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->pinterestClient->isConfigured() || !$this->pinterestClient->isConnected() || !$this->pinterestClient->needsRefresh()) {
            $io->success('No Pinterest refresh due.');

            return Command::SUCCESS;
        }

        try {
            $this->pinterestClient->refresh();
        } catch (\RuntimeException $exception) {
            if (!$this->isMailDue()) {
                $io->warning(sprintf('Pinterest refused the refresh: %s', $exception->getMessage()));

                return Command::SUCCESS;
            }

            $error = $this->mailer->send(
                $this->translator->trans('email.social_pinterest_refresh_failed_subject', [], 'social'),
                $this->translator->trans('email.social_pinterest_refresh_failed_body', ['%error%' => $exception->getMessage()], 'social') . "\n\n" . $this->mailer->url(SocialConnectionsController::ROUTE),
            );
            // A warning as the reminders give, an error sending a CRITICAL alert
            $io->warning(sprintf('Pinterest refused the refresh: %s%s', $exception->getMessage(), null === $error ? '' : sprintf(' (and the email was not sent: %s)', $error)));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Pinterest tokens renewed, the access token until %s.', $this->pinterestClient->expiresAt()?->format('d/m/Y')));

        return Command::SUCCESS;
    }

    // The first failing night - the access token entered the refresh window within the last day - or LAST_REMINDER days before the refresh token ends
    private function isMailDue(): bool
    {
        $expiresAt = $this->pinterestClient->expiresAt();
        $firstNight = null !== $expiresAt && $expiresAt > new \DateTimeImmutable(PinterestClient::REFRESH_BEFORE)->modify('-1 day');

        return $firstNight || self::LAST_REMINDER === $this->daysLeft($this->pinterestClient->refreshExpiresAt());
    }
}
