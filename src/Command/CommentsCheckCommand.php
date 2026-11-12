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
use c975L\SocialBundle\Controller\Management\SocialConnectionsController;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Model\PostComment;
use c975L\SocialBundle\Service\SocialAdminMailer;
use c975L\SocialBundle\Service\SocialCommentChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

// Emails "email-to" the new comments on the posts of the last "social-comments-alert-days" days (0 reads nothing), one email per check whatever their number, each quoted so that one needing no answer is seen without opening the post
#[AsCommand(
    name: 'c975l:social:comments:check',
    description: 'Emails the new comments on the posts gone out lately',
)]
class CommentsCheckCommand extends Command
{
    // How long a comment is quoted, the post holding the rest
    private const int QUOTE_LENGTH = 280;

    public function __construct(
        private readonly SocialCommentChecker $checker,
        private readonly ConfigServiceInterface $configService,
        private readonly SocialAdminMailer $mailer,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the email without sending it, nothing remembered');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = (int) $this->configService->get('social-comments-alert-days');
        if ($days <= 0) {
            $io->note('social-comments-alert-days is 0, nothing is read.');

            return Command::SUCCESS;
        }

        $read = $this->checker->read($days);
        $news = array_values(array_filter($read, static fn (array $row): bool => $row['added'] > 0));
        if ([] === $news) {
            $io->success('No new comment.');
        } elseif (!$this->announce($news, $input, $io)) {
            return Command::SUCCESS;
        }
        if ($input->getOption('dry-run')) {
            return Command::SUCCESS;
        }

        // Remembered once told, so an email that failed tells them at the next check
        foreach ($read as $row) {
            $row['target']->setCommentCount($row['count']);
        }
        $this->entityManager->flush();

        return Command::SUCCESS;
    }

    // The email of the new comments, false when it could not go - a warning as PublishCommand gives, an error sending a CRITICAL alert at each check
    /** @param list<array{target: SocialPostTarget, count: int, added: int, url: ?string, comments: ?list<PostComment>}> $news */
    private function announce(array $news, InputInterface $input, SymfonyStyle $io): bool
    {
        $subject = $this->translator->trans('email.social_comments_subject', ['%count%' => array_sum(array_column($news, 'added'))], 'social');
        $body = $this->translator->trans('email.social_comments_body', [], 'social') . "\n\n" . implode("\n\n", array_map($this->line(...), $news));
        $io->title($subject);
        $io->writeln(OutputFormatter::escape($body));
        if ($input->getOption('dry-run')) {
            return true;
        }

        $error = $this->mailer->send($subject, $body);
        if (null !== $error) {
            $io->warning(sprintf('The new comments were not announced: %s', $error));

            return false;
        }

        return true;
    }

    // The post, its network, how many are new, what they say and where to read them - the post on the calendar when the network gives no address
    /** @param array{target: SocialPostTarget, count: int, added: int, url: ?string, comments: ?list<PostComment>} $row */
    private function line(array $row): string
    {
        $post = $row['target']->getPost();
        $url = $row['url'] ?? $this->mailer->url(SocialCalendarController::ROUTE, ['date' => $post->getPlannedAt()->format('Y-m-d'), 'post' => $post->getId()]);
        $lines = [sprintf('- %s (%s) : +%d', $post->getTitle(), ucfirst($row['target']->getNetwork()), $row['added'])];
        foreach ($row['comments'] ?? [] as $comment) {
            $lines[] = '  ' . $this->quote($comment);
        }

        // The text refused, most often a connection made before its reading was asked: said with where to connect again
        if (null === $row['comments']) {
            $lines[] = '  ' . $this->translator->trans('email.social_comments_unread', [], 'social');
            $lines[] = '  ' . $this->mailer->url(SocialConnectionsController::ROUTE);
        }
        $lines[] = '  ' . $url;

        return implode("\n", $lines);
    }

    // A comment on one line, cut past QUOTE_LENGTH, then its author
    private function quote(PostComment $comment): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $comment->text));
        if (mb_strlen($text) > self::QUOTE_LENGTH) {
            $text = rtrim(mb_substr($text, 0, self::QUOTE_LENGTH - 1)) . '…';
        }
        $quote = $this->translator->trans('email.social_comments_quote', ['%text%' => $text], 'social');

        return '' !== trim($comment->author) ? $quote . ' - ' . trim($comment->author) : $quote;
    }
}
