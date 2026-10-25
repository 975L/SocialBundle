<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Service\SocialDraftImporter;
use c975L\SocialBundle\Service\SocialPublisher;
use c975L\SocialBundle\Service\SocialSeriesGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class SocialDraftImporterTest extends TestCase
{
    private string $dir;

    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/social-draft-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/public/medias', 0o777, true);
        file_put_contents($this->dir . '/card.png', 'png');
        file_put_contents($this->dir . '/public/medias/card.jpg', 'jpeg');
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), array_filter([$this->dir . '/card.png', $this->dir . '/public/medias/card.jpg'], is_file(...)));
        rmdir($this->dir . '/public/medias');
        rmdir($this->dir . '/public');
        rmdir($this->dir);
    }

    // LinkedIn taking a first comment, Bluesky none - both connected
    private function importer(): SocialDraftImporter
    {
        $publisher = $this->createStub(SocialPublisher::class);
        $publisher->method('getConnectedNetworkNames')->willReturn(['bluesky', 'linkedin']);
        $publisher->method('supportsComment')->willReturnCallback(static fn (string $network): bool => 'linkedin' === $network);
        $publisher->method('createManual')->willReturnCallback(static function (\DateTimeImmutable $at): SocialPost {
            $post = new SocialPost(SocialPost::SOURCE_MANUAL, 'id', '', '', null, $at);
            new SocialPostTarget($post, 'bluesky', '');
            new SocialPostTarget($post, 'linkedin', '');

            return $post;
        });

        $generator = $this->createStub(SocialSeriesGenerator::class);
        $generator->method('store')->willReturn(['medias/card.jpg', 'image/jpeg', 1200, 1200, null]);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        return new SocialDraftImporter($publisher, $generator, $entityManager, $this->dir);
    }

    // Every mistake of the file listed at once, and no draft while there is one
    public function testAWrongFileListsEveryMistake(): void
    {
        $read = $this->importer()->read(['posts' => [['at' => 'never', 'networks' => ['youtube'], 'text' => '', 'media' => 'missing.png']]], $this->dir);

        $this->assertCount(5, $read['errors']);
        $this->assertSame([], $read['drafts']);
    }

    // A moment gone, relative or rolled over is refused, a draft never placed where it cannot be approved
    public function testAMomentNotWrittenAsTheHelpSaysOrGoneIsRefused(): void
    {
        foreach (['2020-01-06 09:30' => 'in the past', 'monday' => 'is not a date', '2026-13-45 09:30' => 'is not a date'] as $at => $error) {
            $read = $this->importer()->read(['title' => 'T', 'posts' => [['at' => $at, 'networks' => ['linkedin'], 'text' => 'One idea']]], $this->dir);

            $this->assertStringContainsString($error, implode("\n", $read['errors']), $at);
        }
    }

    // The drafts make one imported series, each on its own networks alone, with its text, its picture and its first comment
    public function testTheDraftsMakeOneImportedSeries(): void
    {
        $importer = $this->importer();
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 09:30';
        $read = $importer->read(['title' => 'Bundles - November', 'posts' => [['at' => $at, 'networks' => ['linkedin'], 'text' => 'One idea', 'comment' => 'Read more: https://example.org', 'media' => 'card.png']]], $this->dir);
        $this->assertSame([], $read['errors']);

        $posts = $importer->import('Bundles - November', $read['drafts']);

        $this->assertInstanceOf(SocialSeries::class, $this->persisted[0]);
        $this->assertSame(SocialSeriesGenerator::MODE_IMPORT, $this->persisted[0]->getMode());
        $this->assertCount(1, $posts);
        $this->assertSame($this->persisted[0], $posts[0]->getSeries());
        $this->assertSame('One idea', $posts[0]->getText());
        $this->assertSame($at, $posts[0]->getPlannedAt()->format('Y-m-d H:i'));
        $this->assertCount(1, $posts[0]->getMedias());
        $comments = [];
        foreach ($posts[0]->getTargets() as $target) {
            $comments[$target->getNetwork()] = $target->getComment();
        }
        $this->assertSame(['linkedin' => 'Read more: https://example.org'], $comments);
        $this->assertFileExists($this->dir . '/card.png');
    }
}
