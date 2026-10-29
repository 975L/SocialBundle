<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Management;

use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Management\SocialDraftAlertProvider;
use c975L\SocialBundle\Repository\SocialPostRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// A draft never goes out: the ones due within a day are said on the dashboard, the calendar opening on the first
class SocialDraftAlertProviderTest extends TestCase
{
    /**
     * @param list<SocialPost> $posts
     *
     * @return list<array<string, mixed>>
     */
    private function alerts(array $posts): array
    {
        $repository = $this->createStub(SocialPostRepository::class);
        $repository->method('findDraftsPlannedBetween')->willReturn($posts);
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('ROLE_EDITOR');
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(static fn (string $route, array $query = []): string => $route . '?' . http_build_query($query));
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $parameters = []): string => $id . json_encode($parameters));

        return new SocialDraftAlertProvider($repository, $configService, $urlGenerator, $translator)->getAlerts();
    }

    private function post(int $id, string $plannedAt): SocialPost
    {
        $post = new SocialPost('gallery_media', (string) $id, 'Post ' . $id, 'https://example.org/' . $id, null, new \DateTimeImmutable($plannedAt));
        new \ReflectionProperty(SocialPost::class, 'id')->setValue($post, $id);

        return $post;
    }

    // Which posts are drafts is the repository's (see SocialPostRepositoryTest)
    public function testNothingIsSaidWithoutADraftDue(): void
    {
        $this->assertSame([], $this->alerts([]));
    }

    // Counted together, the first read named and opened
    public function testTheDraftsDueAreCountedAndTheFirstOpened(): void
    {
        $alerts = $this->alerts([$this->post(3, '+3 hours'), $this->post(2, '+20 hours')]);

        $this->assertCount(1, $alerts);
        $this->assertSame(Config::SEVERITY_WARNING, $alerts[0]['severity']);
        $this->assertStringContainsString('"%count%":2', $alerts[0]['label']);
        $this->assertStringContainsString('Post 3', (string) $alerts[0]['description']);
        $this->assertStringContainsString('post=3', $alerts[0]['url']);
        $this->assertStringContainsString('view=day', $alerts[0]['url']);
        $this->assertSame('ROLE_EDITOR', $alerts[0]['role']);
    }
}
