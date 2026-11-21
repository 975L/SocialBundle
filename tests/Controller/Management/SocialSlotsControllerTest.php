<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Controller\Management;

use c975L\SocialBundle\Controller\Management\SocialSlotsController;
use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Entity\SocialSlot;
use c975L\SocialBundle\Repository\SocialSeriesRepository;
use c975L\SocialBundle\Repository\SocialSlotRepository;
use c975L\SocialBundle\Service\SocialSlotCalendar;
use c975L\SocialBundle\Service\SocialSlotRelayer;
use c975L\SocialBundle\Tests\Controller\ControllerTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

// The template week's actions: a slot taken off and a series' drafts laid again only with the screen's token
class SocialSlotsControllerTest extends TestCase
{
    use ControllerTestTrait;

    // A form of the week posted with the given fields
    /** @param array<string, string> $fields */
    private function posted(array $fields): Request
    {
        $request = Request::create('/management/social-slots', 'POST', $fields);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function controller(Request $request, EntityManagerInterface $entityManager, ?SocialSlotRelayer $relayer = null): SocialSlotsController
    {
        $slots = $this->createStub(SocialSlotRepository::class);
        $slots->method('find')->willReturn(new SocialSlot()->setLabel('item-1'));
        $series = $this->createStub(SocialSeriesRepository::class);
        $series->method('find')->willReturn(new SocialSeries('item-1', 1, 'days', 1, [], 'text', 'Text', [], ['bluesky']));

        $controller = new SocialSlotsController($this->configService(), $slots, $series, new SocialSlotCalendar(), $relayer ?? $this->createStub(SocialSlotRelayer::class), $entityManager);
        $controller->setContainer($this->createContainer($request));

        return $controller;
    }

    // A slot is taken off with the screen's token, a forged request leaving it on the week
    public function testASlotIsDeletedWithTheTokenOnly(): void
    {
        $forged = $this->posted(['id' => '1', 'token' => 'forged']);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('remove');
        $this->controller($forged, $entityManager)->delete($forged);

        $request = $this->posted(['id' => '1', 'token' => 'valid']);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('remove');
        $response = $this->controller($request, $entityManager)->delete($request);

        $this->assertSame(['success' => ['flash.social_slot_deleted']], $this->flashes($request));
        $this->assertTrue($response->isRedirect('/' . SocialSlotsController::ROUTE));
    }

    // A series' drafts are laid again with the screen's token, how many moved said
    public function testTheDraftsAreLaidAgainWithTheTokenOnly(): void
    {
        $forged = $this->posted(['series' => '1', 'token' => 'forged']);
        $relayer = $this->createMock(SocialSlotRelayer::class);
        $relayer->expects($this->never())->method('relay');
        $this->controller($forged, $this->createStub(EntityManagerInterface::class), $relayer)->relay($forged);

        $request = $this->posted(['series' => '1', 'token' => 'valid']);
        $relayer = $this->createMock(SocialSlotRelayer::class);
        $relayer->expects($this->once())->method('relay')->willReturn(2);
        $this->controller($request, $this->createStub(EntityManagerInterface::class), $relayer)->relay($request);

        $this->assertSame(['success' => ['flash.social_slot_relayed']], $this->flashes($request));
    }
}
