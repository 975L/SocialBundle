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
use c975L\SocialBundle\Enum\SocialPostStatus;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Service\SocialAdminMailer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

// Tells "email-to" of the posts planned until the day after tomorrow still waiting on a network's approval, a draft never going out and the dashboard's alert being seen only when opened. Scheduled once each morning (see SocialMaintenanceTaskProvider), each post told from two days ahead until approved, a draft made later told too
#[AsCommand(
    name: 'c975l:social:drafts:reminder',
    description: 'Emails the social posts due until the day after tomorrow still in draft',
)]
class DraftsReminderCommand extends Command
{
    // How many days ahead the posts are first told, time enough to approve them
    public const int DAYS_AHEAD = 2;

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

        $drafts = $this->postRepository->findDraftsPlannedBetween(new \DateTimeImmutable(), new \DateTimeImmutable('today')->modify('+' . (self::DAYS_AHEAD + 1) . ' days'));
        if ([] === $drafts) {
            $io->success('No post due until the day after tomorrow in draft.');

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

    // The post, its moment, its networks still in draft and its panel on the calendar's day - absolute, a console having no request to take the host from
    private function line(SocialPost $post): string
    {
        $networks = [];
        foreach ($post->getTargets() as $target) {
            if (SocialPostStatus::Draft === $target->getStatus()) {
                $networks[] = (string) $target;
            }
        }
        $url = $this->mailer->url(SocialCalendarController::ROUTE, ['view' => SocialCalendarController::DAY, 'date' => $post->getPlannedAt()->format('Y-m-d'), 'post' => $post->getId()]);

        return sprintf("- %s : %s (%s)\n  %s", $post->getPlannedAt()->format('d/m/Y H:i'), $post->getTitle(), implode(', ', $networks), $url);
    }
}
