<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Command;

use c975L\SocialBundle\Service\SocialDraftImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// Makes drafts written elsewhere from a JSON file, as one series (see SocialDraftImporter) - a picture's path read from the file's folder. Nothing is sent: they wait on the calendar to be read and approved
#[AsCommand(
    name: 'c975l:social:draft',
    description: 'Makes drafts from a JSON file, as one series waiting for approval',
)]
class DraftCommand extends Command
{
    public function __construct(
        private readonly SocialDraftImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'The JSON file: {"title": "...", "posts": [{"at": "2026-11-02 09:30", "networks": ["linkedin"], "text": "...", "comment": "...", "media": "card.png", "url": "..."}]}')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Check the file and list the drafts without writing anything')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $file = (string) $input->getArgument('file');
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!\is_array($data)) {
            $io->error(sprintf('"%s" is not a readable JSON file.', $file));

            return Command::FAILURE;
        }

        $read = $this->importer->read($data, \dirname($file));
        if ([] !== $read['errors']) {
            $io->error($read['errors']);

            return Command::FAILURE;
        }

        $io->table(['At', 'Networks', 'Text', 'Comment', 'Media'], array_map(static fn (array $draft): array => [
            $draft['at']->format('d/m/Y H:i'),
            implode(', ', $draft['networks']),
            mb_strimwidth(str_replace("\n", ' ', $draft['text']), 0, 50, '…'),
            null === $draft['comment'] ? '' : mb_strimwidth(str_replace("\n", ' ', $draft['comment']), 0, 30, '…'),
            null === $draft['media'] ? '' : basename($draft['media']),
        ], $read['drafts']));

        if ($input->getOption('dry-run')) {
            $io->note('Dry run: nothing written.');

            return Command::SUCCESS;
        }

        $posts = $this->importer->import(trim((string) $data['title']), $read['drafts']);
        $io->success(sprintf('%d draft(s) waiting for approval on the calendar.', \count($posts)));

        return Command::SUCCESS;
    }
}
