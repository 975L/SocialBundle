<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Command;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Controller\Management\SocialCalendarController;
use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Repository\SocialSeriesRepository;
use c975L\SocialBundle\Service\SocialAdminMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

// Announces by email, to "email-to", the series whose last post comes within "social-series-ending-days" (0 announces nothing) - once per ending, a prolonged series announced again when its new end comes near. Scheduled nightly (see SocialMaintenanceTaskProvider)
#[AsCommand(
    name: 'c975l:social:series:ending',
    description: 'Emails the social series coming to their end',
)]
class SeriesEndingCommand extends Command
{
    public function __construct(
        private readonly SocialSeriesRepository $seriesRepository,
        private readonly ConfigServiceInterface $configService,
        private readonly SocialAdminMailer $mailer,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Display the series ending without sending anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = (int) $this->configService->get('social-series-ending-days');
        if ($days <= 0) {
            $io->note('social-series-ending-days is 0, nothing is announced.');

            return Command::SUCCESS;
        }

        // The series ending within the delay, not announced yet for that end
        $limit = new \DateTimeImmutable(sprintf('+%d days', $days));
        $ending = array_filter($this->seriesRepository->findWithLastPlannedAt(), static fn (array $row): bool => $row['lastPlannedAt'] <= $limit && $row['lastPlannedAt'] != $row['series']->getNotifiedFor());
        if ([] === $ending) {
            $io->success('No series coming to its end.');

            return Command::SUCCESS;
        }

        $lines = array_map(fn (array $row): string => $this->line($row['series'], $row['lastPlannedAt']), $ending);
        $subject = $this->translator->trans('email.social_series_ending_subject', ['%count%' => \count($ending)], 'social');
        $body = $this->translator->trans('email.social_series_ending_body', [], 'social') . "\n\n" . implode("\n\n", $lines);
        $io->title($subject);
        $io->writeln($body);

        if ($input->getOption('dry-run')) {
            return Command::SUCCESS;
        }

        $error = $this->mailer->send($subject, $body);
        // Not announced, tried again the next night - a warning as PublishCommand gives, an error sending a CRITICAL alert every night
        if (null !== $error) {
            $io->warning(sprintf('The announcement was not sent: %s', $error));

            return Command::SUCCESS;
        }

        // Announced once for this end: a prolongation moves it, and arms the announcement again
        foreach ($ending as $row) {
            $row['series']->setNotifiedFor($row['lastPlannedAt']);
        }
        $this->entityManager->flush();

        return Command::SUCCESS;
    }

    // The series, its last moment and the calendar's week holding it - absolute, a console having no request to take the host from
    private function line(SocialSeries $series, \DateTimeImmutable $lastPlannedAt): string
    {
        $url = $this->mailer->url(SocialCalendarController::ROUTE, ['date' => $lastPlannedAt->format('Y-m-d')]);

        return sprintf("- %s : %s\n  %s", $series->getTitle(), $lastPlannedAt->format('d/m/Y H:i'), $url);
    }
}
