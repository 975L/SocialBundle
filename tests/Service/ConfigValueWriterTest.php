<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\VaultEncryptor;
use c975L\SocialBundle\Service\ConfigValueWriter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ConfigValueWriterTest extends TestCase
{
    /**
     * @param array<string, Config> $configs slug => declared config
     */
    private function createRepository(array $configs): ConfigRepository
    {
        $configRepository = $this->createStub(ConfigRepository::class);
        $configRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?Config => $configs[$criteria['slug']] ?? null
        );

        return $configRepository;
    }

    private function createEncryptor(): VaultEncryptor
    {
        $vaultEncryptor = $this->createStub(VaultEncryptor::class);
        $vaultEncryptor->method('encrypt')->willReturnCallback(static fn (string $value): string => 'C975L:' . $value);

        return $vaultEncryptor;
    }

    private function createWriter(ConfigRepository $configRepository, ?EntityManagerInterface $entityManager = null, ?ConfigServiceInterface $configService = null): ConfigValueWriter
    {
        return new ConfigValueWriter(
            $configRepository,
            $configService ?? $this->createStub(ConfigServiceInterface::class),
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $this->createEncryptor(),
        );
    }

    public function testWriteStoresAPlainValueAsIs(): void
    {
        $config = new Config()->setSlug('social-google-business-account-id')->setIsSensitive(false);

        $this->createWriter($this->createRepository(['social-google-business-account-id' => $config]))
            ->write(['social-google-business-account-id' => '123']);

        $this->assertSame('123', $config->getValue());
    }

    // A refresh token is declared sensitive, so what lands in the table is what VaultEncryptor made of it - never the token itself
    public function testWriteEncryptsASensitiveValue(): void
    {
        $config = new Config()->setSlug('social-google-oauth-refresh-token')->setIsSensitive(true);

        $this->createWriter($this->createRepository(['social-google-oauth-refresh-token' => $config]))
            ->write(['social-google-oauth-refresh-token' => 'refresh-token']);

        $this->assertSame('C975L:refresh-token', $config->getValue());
    }

    // Clearing a sensitive config has nothing to encrypt, and encrypting null would store a ciphertext where the absence of a value is meant
    public function testWriteStoresNullWithoutEncryptingIt(): void
    {
        $config = new Config()->setSlug('social-google-oauth-refresh-token')->setIsSensitive(true);

        $this->createWriter($this->createRepository(['social-google-oauth-refresh-token' => $config]))
            ->write(['social-google-oauth-refresh-token' => null]);

        $this->assertNull($config->getValue());
    }

    // One flush for the lot, so a connection storing a token and the listing it belongs to leaves no half-written state behind
    public function testWriteFlushesOnceForEveryValue(): void
    {
        $configs = [
            'social-google-business-account-id' => new Config()->setSlug('social-google-business-account-id')->setIsSensitive(false),
            'social-google-business-location-id' => new Config()->setSlug('social-google-business-location-id')->setIsSensitive(false),
        ];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->exactly(2))->method('persist');
        $entityManager->expects($this->once())->method('flush');

        $this->createWriter($this->createRepository($configs), $entityManager)->write([
            'social-google-business-account-id' => '123',
            'social-google-business-location-id' => '456',
        ]);
    }

    // ConfigService caches what it reads, so a value written behind its back would stay invisible until the cache expired
    public function testWriteInvalidatesTheConfigCache(): void
    {
        $config = new Config()->setSlug('social-google-business-account-id')->setIsSensitive(false);

        $configService = $this->createMock(ConfigServiceInterface::class);
        $configService->expects($this->once())->method('invalidateCache');

        $this->createWriter($this->createRepository(['social-google-business-account-id' => $config]), null, $configService)
            ->write(['social-google-business-account-id' => '123']);
    }

    // A slug missing from the table means "c975l:config:load-all" was never run, which the message has to say rather than fail on a null further down
    public function testWriteThrowsOnAnUndeclaredConfig(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/load-all/');

        $this->createWriter($this->createRepository([]))->write(['social-google-oauth-refresh-token' => 'refresh-token']);
    }
}
