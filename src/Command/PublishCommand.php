<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Command;

use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Enum\SocialPostStatus;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Service\SocialAdminMailer;
use c975L\SocialBundle\Service\SocialPlanner;
use c975L\SocialBundle\Service\SocialPublisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

// Sends the approved posts whose planned moment has come - scheduled every quarter of an hour (see SocialMaintenanceTaskProvider). "--url" prepares a draft of any page instead, planned at "--at" or the next quarter of an hour, "--dry-run" prints what each network would receive and sends nothing - the JPEG copy Meta would download is written, being the one the post will use
#[AsCommand(
    name: 'c975l:social:publish',
    description: 'Sends the approved social network posts whose planned moment has come',
)]
class PublishCommand extends Command
{
    public function __construct(
        private readonly SocialPublisher $socialPublisher,
        private readonly SocialPlanner $planner,
        private readonly SocialPostRepository $postRepository,
        private readonly SocialAdminMailer $mailer,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Prepare a draft of this page, read from its Open Graph tags')
            ->addOption('at', null, InputOption::VALUE_REQUIRED, 'The moment the draft of "--url" is planned at, the next quarter of an hour by default')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what each network would receive, without sending anything - the template\'s text, the AI never being called');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $url = $input->getOption('url');
        $at = $input->getOption('at');
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $report = \is_string($url)
                ? $this->socialPublisher->prepareUrl($url, $this->planner->round(\is_string($at) ? new \DateTimeImmutable($at) : $this->planner->nextQuarter(new \DateTimeImmutable())), $dryRun)
                : $this->socialPublisher->publishPlanned($dryRun);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        // Nothing due, or no network configured: the normal state of most runs, not a failure
        if ([] === $report) {
            $io->note('Nothing to send.');

            return Command::SUCCESS;
        }

        foreach ($report as $network => $result) {
            if (isset($result['payload'])) {
                $io->section(sprintf('%s (%s)', $network, $result['message']));
                $io->writeln((string) json_encode($result['payload'], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

                continue;
            }

            $io->writeln(sprintf('%s: %s %s', $network, $result['status'], $result['message']));
        }

        if (!$dryRun && !\is_string($url)) {
            $this->announceFailures($report, $io);
        }

        // A refused post is kept, failed, on the screen where it is published again - the run did its job, so a cron has nothing to be woken for
        return Command::SUCCESS;
    }

    // The posts a network refused in this run, emailed to "email-to" with the address publishing them again - once, a failed target being no longer sent by the planned run
    /** @param array<string, array<string, mixed>> $report */
    private function announceFailures(array $report, SymfonyStyle $io): void
    {
        $lines = [];
        foreach ($report as $result) {
            if (SocialPostStatus::Failed->value !== ($result['status'] ?? null)) {
                continue;
            }

            $id = (int) ($result['post'] ?? 0);
            $network = (string) ($result['network'] ?? '');
            $post = $this->postRepository->find($id);
            $lines[] = sprintf(
                "- %s - %s : %s\n  %s",
                $post instanceof SocialPost ? $post->getTitle() : '#' . $id,
                ucfirst($network),
                (string) ($result['message'] ?? ''),
                $this->mailer->url('management_social_post_retry_post', ['entityId' => $id]),
            );
        }
        if ([] === $lines) {
            return;
        }

        $error = $this->mailer->send(
            $this->translator->trans('email.social_post_failed_subject', ['%count%' => \count($lines)], 'social'),
            $this->translator->trans('email.social_post_failed_body', [], 'social') . "\n\n" . implode("\n\n", $lines),
        );
        if (null !== $error) {
            $io->warning(sprintf('The failures were not emailed: %s', $error));
        }
    }
}
