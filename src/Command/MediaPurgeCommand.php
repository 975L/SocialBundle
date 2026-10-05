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
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Service\SocialImageExporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

// Frees the disk of what the networks hold already, once "social-media-retention-days" have passed (0 keeps everything): the medias uploaded for a post gone out everywhere - the post itself staying, as the record of what went out and of the contents taken - and the JPEG copies Meta downloaded. A file of the site's own, only referenced, is never deleted (see SocialMediaUploadListener). Scheduled nightly (see SocialMaintenanceTaskProvider)
#[AsCommand(
    name: 'c975l:social:media:purge',
    description: 'Deletes the medias of the social posts gone out, once their retention has passed',
)]
class MediaPurgeCommand extends Command
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly SocialPostRepository $postRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = (int) $this->configService->get('social-media-retention-days');
        if ($days <= 0) {
            $io->note('Retention set to keep every media.');

            return Command::SUCCESS;
        }

        $before = new \DateTimeImmutable(sprintf('-%d days', $days));

        // Removed from their post, Vich deleting each upload's file with its row
        $medias = 0;
        foreach ($this->postRepository->findPublishedWithMediasBefore($before) as $post) {
            foreach ($post->getMedias()->toArray() as $media) {
                $post->removeMedia($media);
                ++$medias;
            }
        }
        $this->entityManager->flush();

        // The copies sit at the folder's top, the uploads in a folder of their own below it
        $copies = 0;
        foreach (glob($this->projectDir . '/public/' . SocialImageExporter::DIRECTORY . '/*.jpg') ?: [] as $copy) {
            if (filemtime($copy) < $before->getTimestamp() && unlink($copy)) {
                ++$copies;
            }
        }

        $io->success(sprintf('%d media(s) and %d copy(ies) deleted.', $medias, $copies));

        return Command::SUCCESS;
    }
}
