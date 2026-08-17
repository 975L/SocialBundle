<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\VaultEncryptor;
use Doctrine\ORM\EntityManagerInterface;

// ConfigServiceInterface only reads, and the values written here are never typed by hand: the Google connection hands back a refresh token and the ids of the listing it opened, which have to land in the same configs the rest of the bundle reads
class ConfigValueWriter
{
    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly ConfigServiceInterface $configService,
        private readonly EntityManagerInterface $entityManager,
        private readonly VaultEncryptor $vaultEncryptor,
    ) {
    }

    // Writes several configs at once, so a connection storing a token and the listing it belongs to leaves no half-written state behind
    /**
     * @param array<string, string|null> $values slug => value
     */
    public function write(array $values): void
    {
        foreach ($values as $slug => $value) {
            $config = $this->configRepository->findOneBy(['slug' => $slug]);

            if (null === $config) {
                throw new \RuntimeException(sprintf('The config "%s" is not declared: run "c975l:config:load-all" first.', $slug));
            }

            $config->setValue(null !== $value && $config->getIsSensitive() ? $this->vaultEncryptor->encrypt($value) : $value);
            $config->setModification(new \DateTime());
            $this->entityManager->persist($config);
        }

        $this->entityManager->flush();
        $this->configService->invalidateCache();
    }
}
