<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Repository;

use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Repository\SocialPostRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

// The queue and the calendar are read through these queries, a fetch join filtering the very collection it loads being the classic way to get them wrong: run on SQLite in memory rather than on a stub that would only echo what it was told
#[RequiresPhpExtension('pdo_sqlite')]
class SocialPostRepositoryTest extends TestCase
{
    private EntityManagerInterface $entityManager;

    private SocialPostRepository $repository;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([\dirname(__DIR__, 2) . '/src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        // The one a Symfony application maps with, the entities' indexes naming their columns by it
        $config->setNamingStrategy(new UnderscoreNamingStrategy(\CASE_LOWER, true));
        $this->entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        new SchemaTool($this->entityManager)->createSchema([
            $this->entityManager->getClassMetadata(SocialPost::class),
            $this->entityManager->getClassMetadata(SocialPostTarget::class),
        ]);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);
        $this->repository = new SocialPostRepository($registry);
    }

    // A post with a draft on Bluesky and the other target as given
    private function post(string $id, ?\Closure $other = null): void
    {
        $post = new SocialPost('gallery_media', $id, 'Title ' . $id, 'https://example.org/' . $id, null);
        new SocialPostTarget($post, 'bluesky', 'Text');
        $target = new SocialPostTarget($post, 'facebook', 'Text');
        if (null !== $other) {
            $other($target);
        }
        $this->entityManager->persist($post);
        $this->entityManager->flush();
    }

    /**
     * @param list<SocialPost> $posts
     *
     * @return array<string, int>
     */
    private function targetCounts(array $posts): array
    {
        $counts = [];
        foreach ($posts as $post) {
            $counts[$post->getSourceId()] = $post->getTargets()->count();
        }

        return $counts;
    }

    // Approved on one network, a post comes with every target, in the order prepared
    public function testTheQueueHoldsTheApprovedPostsWithAllTheirTargets(): void
    {
        $this->post('1', static fn (SocialPostTarget $target) => $target->approve());
        $this->post('2');
        $this->post('3', static fn (SocialPostTarget $target) => $target->approve());
        $this->entityManager->clear();

        $this->assertSame(['1' => 2, '3' => 2], $this->targetCounts($this->repository->findApproved()));
    }

    public function testTheDraftsAreThePostsWithATextWaitingForAReading(): void
    {
        $this->post('1');
        $this->post('2', static fn (SocialPostTarget $target) => $target->approve());
        $this->entityManager->clear();

        $this->assertSame(['1' => 2, '2' => 2], $this->targetCounts($this->repository->findDrafts()));
    }

    // The calendar's past: what went out within the month shown, the post whole
    public function testThePublishedPostsAreReadWithinTheirWindow(): void
    {
        $this->post('1', static fn (SocialPostTarget $target) => $target->markPublished('id'));
        $this->post('2');
        $this->entityManager->clear();

        $this->assertSame(['1' => 2], $this->targetCounts($this->repository->findPublishedBetween(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+1 day'))));
        $this->assertSame([], $this->repository->findPublishedBetween(new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+2 days')));
    }

    // The tone the AI is shown: what went out on that network, never a draft nor another network's text
    public function testThePublishedTextsOfANetworkAreItsOwn(): void
    {
        $this->post('1', static fn (SocialPostTarget $target) => $target->markPublished('id'));
        $this->post('2');

        $this->assertSame(['Text'], $this->repository->findPublishedTexts('facebook', 3));
        $this->assertSame([], $this->repository->findPublishedTexts('bluesky', 3));
    }
}
