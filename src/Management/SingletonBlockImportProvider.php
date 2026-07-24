<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\SocialBundle\Management;

use c975L\ConfigBundle\Management\ImportProviderInterface;
use c975L\UiBundle\Entity\Block;
use c975L\UiBundle\Repository\BlockRepository;
use Doctrine\ORM\EntityManagerInterface;

// Shared import for a site-wide singleton Block - mirrors SingletonBlockExportProvider. Updates the existing row's "data" in place if this environment already has one (matched by kind, not id, so dev/prod ids never need to align), creates it otherwise - no whole-collection replace needed like PageImportProvider's Blocks, there's only ever one row per kind and it never carries medias/slots
abstract class SingletonBlockImportProvider implements ImportProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BlockRepository $blockRepository,
    ) {
    }

    abstract protected function getKind(): string;

    public function supportsImport(string $kind): bool
    {
        return $this->getKind() === $kind;
    }

    public function import(array $items, ?string $filesDir = null): array
    {
        if ([] === $items) {
            return ['created' => 0, 'updated' => 0];
        }

        $block = $this->blockRepository->findOneByKind($this->getKind());
        $isNew = null === $block;
        $block ??= (new Block())->setKind($this->getKind());

        $block->setData($items[0]['data'] ?? []);
        $this->em->persist($block);
        $this->em->flush();

        return $isNew ? ['created' => 1, 'updated' => 0] : ['created' => 0, 'updated' => 1];
    }
}
