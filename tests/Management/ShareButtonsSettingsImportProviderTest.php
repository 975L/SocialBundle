<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Management;

use c975L\SocialBundle\Management\ShareButtonsSettingsImportProvider;
use c975L\UiBundle\Entity\Block;
use c975L\UiBundle\Repository\BlockRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ShareButtonsSettingsImportProviderTest extends TestCase
{
    private function createBlockRepository(?Block $existingBlock = null): BlockRepository
    {
        $repository = $this->createStub(BlockRepository::class);
        $repository->method('findOneByKind')->willReturn($existingBlock);

        return $repository;
    }

    public function testSupportsImportOnlyMatchesShareButtonsSettingsKind(): void
    {
        $provider = new ShareButtonsSettingsImportProvider($this->createStub(EntityManagerInterface::class), $this->createBlockRepository());

        $this->assertTrue($provider->supportsImport('share_buttons_settings'));
        $this->assertFalse($provider->supportsImport('social_links'));
    }

    public function testImportCreatesTheSingletonBlockWhenThisEnvironmentHasNoneYet(): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $provider = new ShareButtonsSettingsImportProvider($em, $this->createBlockRepository());

        $result = $provider->import([['data' => ['networks' => ['facebook'], 'style' => 'minimal']]]);

        $this->assertSame(['created' => 1, 'updated' => 0], $result);
        $this->assertSame('share_buttons_settings', $persisted[0]->getKind());
        $this->assertSame(['networks' => ['facebook'], 'style' => 'minimal'], $persisted[0]->getData());
    }

    public function testImportUpdatesTheExistingSingletonBlockInPlaceInsteadOfCreatingASecondOne(): void
    {
        $existing = (new Block())->setKind('share_buttons_settings')->setData(['networks' => ['facebook'], 'style' => 'minimal']);

        $provider = new ShareButtonsSettingsImportProvider($this->createStub(EntityManagerInterface::class), $this->createBlockRepository($existing));

        $result = $provider->import([['data' => ['networks' => ['facebook', 'linkedin'], 'style' => 'rounded']]]);

        $this->assertSame(['created' => 0, 'updated' => 1], $result);
        $this->assertSame(['networks' => ['facebook', 'linkedin'], 'style' => 'rounded'], $existing->getData());
    }
}
