<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Command;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Command\MediaPurgeCommand;
use c975L\SocialBundle\Entity\SocialMedia;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Service\SocialImageExporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class MediaPurgeCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/social-purge-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/public/' . SocialImageExporter::DIRECTORY, 0o775, true);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir . '/public/' . SocialImageExporter::DIRECTORY . '/*') ?: []);
        rmdir($this->dir . '/public/' . SocialImageExporter::DIRECTORY);
        rmdir($this->dir . '/public/medias');
        rmdir($this->dir . '/public');
        rmdir($this->dir);
    }

    private function tester(?string $days, SocialPost $post, ?\DateTimeImmutable &$asked = null): CommandTester
    {
        $config = $this->createStub(ConfigServiceInterface::class);
        $config->method('get')->willReturn($days);
        $repository = $this->createStub(SocialPostRepository::class);
        $repository->method('findPublishedWithMediasBefore')->willReturnCallback(static function (\DateTimeImmutable $before) use ($post, &$asked): array {
            $asked = $before;

            return [$post];
        });

        return new CommandTester(new MediaPurgeCommand($config, $repository, $this->createStub(EntityManagerInterface::class), $this->dir));
    }

    private function post(): SocialPost
    {
        $post = new SocialPost(SocialPost::SOURCE_MANUAL, 'abc', 'Hello', '', null, new \DateTimeImmutable('-1 year'));
        $post->addMedia(new SocialMedia($post));

        return $post;
    }

    // Past the retention, a post gone out loses its medias and the JPEG copies their files, a copy still within it being kept
    public function testThePurgeDeletesWhatThePostsGoneOutHeld(): void
    {
        $old = $this->dir . '/public/' . SocialImageExporter::DIRECTORY . '/old.jpg';
        $recent = $this->dir . '/public/' . SocialImageExporter::DIRECTORY . '/recent.jpg';
        touch($old, strtotime('-100 days'));
        touch($recent);
        $post = $this->post();

        $tester = $this->tester('90', $post, $asked);
        $tester->execute([]);

        $this->assertCount(0, $post->getMedias());
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
        $this->assertEqualsWithDelta(strtotime('-90 days'), $asked?->getTimestamp(), 5);
        $this->assertStringContainsString('1 media(s) and 1 copy(ies) deleted.', $tester->getDisplay());
    }

    // A retention of 0 keeps everything
    public function testARetentionOfZeroKeepsEverything(): void
    {
        $post = $this->post();

        $this->tester('0', $post)->execute([]);

        $this->assertCount(1, $post->getMedias());
    }
}
