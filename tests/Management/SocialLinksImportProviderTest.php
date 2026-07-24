<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Management;

use c975L\SocialBundle\Management\SocialLinksImportProvider;
use c975L\UiBundle\Entity\Block;
use c975L\UiBundle\Repository\BlockRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class SocialLinksImportProviderTest extends TestCase
{
    private function createBlockRepository(?Block $existingBlock = null): BlockRepository
    {
        $repository = $this->createStub(BlockRepository::class);
        $repository->method('findOneByKind')->willReturn($existingBlock);

        return $repository;
    }

    public function testSupportsImportOnlyMatchesSocialLinksKind(): void
    {
        $provider = new SocialLinksImportProvider($this->createStub(EntityManagerInterface::class), $this->createBlockRepository());

        $this->assertTrue($provider->supportsImport('social_links'));
        $this->assertFalse($provider->supportsImport('share_buttons_settings'));
    }

    public function testImportDoesNothingWhenItemsIsEmpty(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $result = (new SocialLinksImportProvider($em, $this->createBlockRepository()))->import([]);

        $this->assertSame(['created' => 0, 'updated' => 0], $result);
    }

    public function testImportCreatesTheSingletonBlockWhenThisEnvironmentHasNoneYet(): void
    {
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $provider = new SocialLinksImportProvider($em, $this->createBlockRepository());

        $result = $provider->import([['data' => ['links' => [['label' => 'X', 'url' => 'https://x.com']]]]]);

        $this->assertSame(['created' => 1, 'updated' => 0], $result);
        $this->assertSame('social_links', $persisted[0]->getKind());
        $this->assertSame(['links' => [['label' => 'X', 'url' => 'https://x.com']]], $persisted[0]->getData());
    }

    public function testImportUpdatesTheExistingSingletonBlockInPlaceInsteadOfCreatingASecondOne(): void
    {
        $existing = (new Block())->setKind('social_links')->setData(['links' => [['label' => 'Old', 'url' => 'https://old.com']]]);

        $provider = new SocialLinksImportProvider($this->createStub(EntityManagerInterface::class), $this->createBlockRepository($existing));

        $result = $provider->import([['data' => ['links' => [['label' => 'New', 'url' => 'https://new.com']]]]]);

        $this->assertSame(['created' => 0, 'updated' => 1], $result);
        $this->assertSame(['links' => [['label' => 'New', 'url' => 'https://new.com']]], $existing->getData());
    }
}
