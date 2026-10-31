<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Command;

use c975L\SocialBundle\Service\SocialPublisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// The contents the site's sources still have free - neither posted nor held by a draft, a source's own delay of repetition counted - each named as an imported draft's "source" says it ("book:12"): what posts written elsewhere are chosen among, so that the site keeps the trace of what went out
#[AsCommand(
    name: 'c975l:social:contents',
    description: 'Lists the contents still free in the social sources, as an imported draft names them',
)]
class ContentsCommand extends Command
{
    public function __construct(
        private readonly SocialPublisher $socialPublisher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('sources', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'The source types to read ("book", "product"), every source by default')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'The most contents listed per source', '50')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Write them as JSON, for a script')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $free = array_map(static fn (array $row): array => [
            'source' => $row['source'] . ':' . $row['content']->sourceId,
            'scope' => $row['scope'],
            'title' => $row['content']->title,
            'url' => $row['content']->url,
            'locale' => $row['content']->locale,
        ], $this->socialPublisher->freeContents(array_values((array) $input->getArgument('sources')), max(1, (int) $input->getOption('limit'))));

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($free, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        if ([] === $free) {
            $io->success('No content left free.');

            return Command::SUCCESS;
        }
        $io->table(['Source', 'Group', 'Title', 'Language'], array_map(static fn (array $row): array => [$row['source'], (string) $row['scope'], mb_strimwidth($row['title'], 0, 60, '…'), (string) $row['locale']], $free));

        return Command::SUCCESS;
    }
}
