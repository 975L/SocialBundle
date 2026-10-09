<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Repository;

use c975L\SocialBundle\Entity\SocialMedia;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Repository\SocialSeriesRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

// The series' last moments, read with MAX() over their posts: run on SQLite in memory rather than on a stub
#[RequiresPhpExtension('pdo_sqlite')]
class SocialSeriesRepositoryTest extends TestCase
{
    private EntityManagerInterface $entityManager;

    private SocialSeriesRepository $repository;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 2) . '/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(\CASE_LOWER, true));
        $this->entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        new SchemaTool($this->entityManager)->createSchema([
            $this->entityManager->getClassMetadata(SocialPost::class),
            $this->entityManager->getClassMetadata(SocialPostTarget::class),
            $this->entityManager->getClassMetadata(SocialMedia::class),
            $this->entityManager->getClassMetadata(SocialSeries::class),
        ]);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);
        $this->repository = new SocialSeriesRepository($registry);
    }

    // A series saved with a post planned at each moment given
    private function series(string $title, string ...$moments): SocialSeries
    {
        $series = new SocialSeries($title, \count($moments), 'days', 1, [], 'text', $title, [], ['bluesky']);
        $this->entityManager->persist($series);
        foreach ($moments as $moment) {
            $post = new SocialPost(SocialPost::SOURCE_MANUAL, bin2hex(random_bytes(4)), $title, '', null, new \DateTimeImmutable($moment));
            $post->setSeries($series);
            $this->entityManager->persist($post);
        }
        $this->entityManager->flush();

        return $series;
    }

    // The last moment of a series is its latest post's, none for a series with no post left
    public function testTheLastMomentIsTheLatestPosts(): void
    {
        $series = $this->series('Photo du soir', '2026-11-01 21:00', '2026-11-03 21:00', '2026-11-02 21:00');
        $empty = $this->series('Empty');

        $this->assertEquals(new \DateTimeImmutable('2026-11-03 21:00'), $this->repository->findLastPlannedAt($series));
        $this->assertNull($this->repository->findLastPlannedAt($empty));
    }

    // Every series comes with its last moment, a series with no post left out
    public function testEverySeriesComesWithItsLastMoment(): void
    {
        $this->series('Photo du soir', '2026-11-01 21:00', '2026-11-03 21:00');
        $this->series('Empty');

        $rows = $this->repository->findWithLastPlannedAt();

        $this->assertCount(1, $rows);
        $this->assertSame('Photo du soir', $rows[0]['series']->getTitle());
        $this->assertEquals(new \DateTimeImmutable('2026-11-03 21:00'), $rows[0]['lastPlannedAt']);
    }
}
