<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\SocialBundle\Management;

use c975L\ConfigBundle\Management\ExportProviderInterface;
use c975L\UiBundle\Repository\BlockRepository;

// Shared export for a site-wide singleton Block - a single row matched by a fixed "kind", never attached to any Page/Menu (see SocialLinksCrudController/ShareButtonsSettingsCrudController), so PageExportProvider/MenuExportProvider's own Block walk never reaches it. Concrete subclasses only name the kind - neither singleton carries medias, so only "data" needs round-tripping
abstract class SingletonBlockExportProvider implements ExportProviderInterface
{
    public function __construct(
        private readonly BlockRepository $blockRepository,
    ) {
    }

    public function exportAll(): array
    {
        $block = $this->blockRepository->findOneByKind($this->getKind());
        if (null === $block) {
            return ['items' => [], 'files' => []];
        }

        return ['items' => [['data' => $block->getData()]], 'files' => []];
    }
}
