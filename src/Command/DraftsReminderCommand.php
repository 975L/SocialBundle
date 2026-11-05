<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Command;

use c975L\SocialBundle\Controller\Management\SocialCalendarController;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Management\SocialDraftAlertProvider;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Service\SocialAdminMailer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

// Tells "email-to" of the posts planned within a day still waiting on a network's approval, a draft never going out and the dashboard's alert being seen only when opened. Scheduled once each morning (see SocialMaintenanceTaskProvider), the window of a day telling each post once
#[AsCommand(
    name: 'c975l:social:drafts:reminder',
    description: 'Emails the social posts due within a day still in draft',
)]
class DraftsReminderCommand extends Command
{
    public function __construct(
        private readonly SocialPostRepository $postRepository,
        private readonly SocialAdminMailer $mailer,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Display the posts in draft without sending anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $now = new \DateTimeImmutable();
        $drafts = $this->postRepository->findDraftsPlannedBetween($now, $now->modify(SocialDraftAlertProvider::NOTICE));
        if ([] === $drafts) {
            $io->success('No post due within a day in draft.');

            return Command::SUCCESS;
        }

        $subject = $this->translator->trans('email.social_drafts_reminder_subject', ['%count%' => \count($drafts)], 'social');
        $body = $this->translator->trans('email.social_drafts_reminder_body', [], 'social') . "\n\n" . implode("\n\n", array_map($this->line(...), $drafts));
        $io->title($subject);
        $io->writeln($body);

        if ($input->getOption('dry-run')) {
            return Command::SUCCESS;
        }

        $error = $this->mailer->send($subject, $body);
        // A warning as PublishCommand gives, an error sending a CRITICAL alert
        if (null !== $error) {
            $io->warning(sprintf('The reminder was not sent: %s', $error));
        }

        return Command::SUCCESS;
    }

    // The post, its moment and its panel on the calendar's day - absolute, a console having no request to take the host from
    private function line(SocialPost $post): string
    {
        $url = $this->mailer->url(SocialCalendarController::ROUTE, ['view' => SocialCalendarController::DAY, 'date' => $post->getPlannedAt()->format('Y-m-d'), 'post' => $post->getId()]);

        return sprintf("- %s : %s\n  %s", $post->getPlannedAt()->format('d/m/Y H:i'), $post->getTitle(), $url);
    }
}
