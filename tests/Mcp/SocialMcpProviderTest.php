<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Mcp;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Mcp\SocialMcpProvider;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Service\SocialPlanner;
use c975L\SocialBundle\Service\SocialPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Server\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;

class SocialMcpProviderTest extends TestCase
{
    // The tools named under the "social" key, the writing ones flagged
    public function testTools(): void
    {
        $tools = iterator_to_array($this->provider()->getMcpTools(), false);

        $this->assertSame('social', $this->provider()->getMcpKey());
        $this->assertSame(['social_list', 'social_get', 'social_create', 'social_edit'], array_map(static fn ($tool): string => $tool->name, $tools));
        $this->assertSame([false, false, true, true], array_map(static fn ($tool): bool => $tool->writes, $tools));
    }

    // The posts planned in the days asked, in their order, each network with its status
    public function testList(): void
    {
        $later = $this->post('item-2', '2026-11-23 09:00');
        $sooner = $this->post('item-1', '2026-11-22 09:00');
        $repository = $this->createStub(SocialPostRepository::class);
        $repository->method('findPlannedBetween')->willReturn([$later, $sooner]);

        $list = $this->provider($repository)->list('2026-11-21', '2026-11-30');

        $this->assertSame(['item-1', 'item-2'], array_column($list, 'title'));
        $this->assertSame(['catalog' => 'draft'], $list[0]['networks']);

        $this->expectException(ToolCallException::class);
        $this->provider($repository)->list('2026-11-30', '2026-11-21');
    }

    // A draft written at the moment asked, never approved, a moment gone refused
    public function testCreateDraft(): void
    {
        $persisted = [];
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $created = $this->provider(entityManager: $entityManager)->create(' text-2 ', '2026-11-22 09:05', 'https://example.com/item-1');

        $this->assertSame(['planned' => '2026-11-22 09:00', 'networks' => ['catalog']], ['planned' => $created['planned'], 'networks' => $created['networks']]);
        $this->assertInstanceOf(SocialPost::class, $persisted[0]);
        $this->assertSame(['text-2', 'https://example.com/item-1', 'draft'], [$persisted[0]->getText(), $persisted[0]->getUrl(), $persisted[0]->getState()]);

        $this->expectException(ToolCallException::class);
        $this->provider()->create('text-1', '2026-11-20 09:00');
    }

    // A post read with its text and each network's version
    public function testGet(): void
    {
        $repository = $this->createStub(SocialPostRepository::class);
        $repository->method('find')->willReturn($this->post('item-1', '2026-11-22 09:00'));

        $post = $this->provider($repository)->get(1);

        $this->assertSame(['item-1', null, null], [$post['text'], $post['url'], $post['series']]);
        $this->assertSame([['network' => 'catalog', 'status' => 'draft', 'text' => 'item-1', 'comment' => null, 'published' => null, 'error' => null]], $post['targets']);
    }

    // A day PHP would roll over is refused rather than read as another one
    public function testListRefusesADayRolledOver(): void
    {
        $this->expectException(ToolCallException::class);
        $this->provider()->list('2026-02-30');
    }

    // An address a post may not hold is refused before anything is written
    public function testCreateRefusesAnAddressNotWeb(): void
    {
        $this->expectException(ToolCallException::class);
        $this->provider()->create('text-1', url: 'ftp://example.com/item-1');
    }

    // A network not connected is refused before anything is written
    public function testCreateRefusesANetworkNotConnected(): void
    {
        $this->expectException(ToolCallException::class);
        $this->provider()->create('text-1', networks: ['other']);
    }

    // A moment gone is refused, a draft never planned in the past
    public function testCreateRefusesAMomentGone(): void
    {
        $this->expectException(ToolCallException::class);
        $this->provider()->create('text-1', '2026-11-21 09:00');
    }

    // A minute another post holds is refused, naming it, whatever its networks
    public function testCreateRefusesAMinuteHeld(): void
    {
        $repository = $this->createStub(SocialPostRepository::class);
        $repository->method('findTakenAt')->willReturn(['2026-11-22 09:00' => 'item-2']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The moment 2026-11-22 09:00 is already held by "item-2"');
        $this->provider($repository)->create('text-1', '2026-11-22 09:00');
    }

    // An empty text is refused before anything is written
    public function testCreateRefusesAnEmptyText(): void
    {
        $this->expectException(ToolCallException::class);
        $this->provider()->create('  ');
    }

    // A post written from a content, without a text of its own, is left to the back office
    public function testEditRefusesAPostWrittenFromAContent(): void
    {
        $post = new SocialPost('book', '12', 'item-1', '', null, new \DateTimeImmutable('2026-11-22 09:00'));
        new SocialPostTarget($post, 'catalog', 'item-1');
        $repository = $this->createStub(SocialPostRepository::class);
        $repository->method('find')->willReturn($post);

        $this->expectException(ToolCallException::class);
        $this->provider($repository)->edit(1, 'text-2');
    }

    // A draft's text corrected, its networks' texts written again
    public function testEdit(): void
    {
        $post = $this->post('item-1', '2026-11-22 09:00');
        $repository = $this->createStub(SocialPostRepository::class);
        $repository->method('find')->willReturn($post);
        $publisher = $this->createMock(SocialPublisher::class);
        $publisher->expects($this->once())->method('rewriteTargets')->with($post);

        $this->assertSame('draft', $this->provider($repository, publisher: $publisher)->edit(1, 'text-2')['state']);
        $this->assertSame('text-2', $post->getText());
    }

    // An approved post is left to the back office
    public function testEditRefusesAnApprovedPost(): void
    {
        $post = $this->post('item-1', '2026-11-22 09:00');
        $post->approve();
        $repository = $this->createStub(SocialPostRepository::class);
        $repository->method('find')->willReturn($post);

        $this->expectException(ToolCallException::class);
        $this->provider($repository)->edit(1, 'text-2');
    }

    // An account that does not edit the calendar is refused every tool
    public function testRefusedWithoutTheRole(): void
    {
        $this->expectException(ToolCallException::class);
        $this->provider(granted: false)->list();
    }

    // A post written by hand on the "catalog" network, still a draft
    private function post(string $title, string $plannedAt): SocialPost
    {
        $post = new SocialPost(SocialPost::SOURCE_MANUAL, $title, $title, '', null, new \DateTimeImmutable($plannedAt))->setText($title);
        new SocialPostTarget($post, 'catalog', $title);

        return $post;
    }

    // The provider for an account editing the calendar or not, "catalog" the only network connected
    private function provider(?SocialPostRepository $repository = null, ?EntityManagerInterface $entityManager = null, ?SocialPublisher $publisher = null, bool $granted = true): SocialMcpProvider
    {
        if (null === $publisher) {
            $publisher = $this->createStub(SocialPublisher::class);
            $publisher->method('createManual')->willReturnCallback(fn (\DateTimeImmutable $at): SocialPost => $this->post('', $at->format('Y-m-d H:i')));
            $publisher->method('getConnectedNetworkNames')->willReturn(['catalog']);
        }
        $config = $this->createStub(ConfigServiceInterface::class);
        $config->method('get')->willReturn('ROLE_EDITOR');
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn($granted);

        return new SocialMcpProvider($repository ?? $this->createStub(SocialPostRepository::class), $publisher, new SocialPlanner(), $entityManager ?? $this->createStub(EntityManagerInterface::class), $config, $security, new MockClock('2026-11-21 10:00'));
    }
}
