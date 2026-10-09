<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Controller;

use c975L\SocialBundle\Controller\Management\SocialCalendarController;
use c975L\SocialBundle\Controller\SocialCalendarFeedController;
use c975L\SocialBundle\Service\SocialCalendarFeed;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class SocialCalendarFeedControllerTest extends TestCase
{
    private const string TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function controller(SocialCalendarFeed $feed): SocialCalendarFeedController
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route, array $parameters, int $referenceType): string => SocialCalendarController::ROUTE === $route && UrlGeneratorInterface::ABSOLUTE_URL === $referenceType ? 'https://example.org/management/social-calendar' : '/wrong');
        $services = ['router' => $router];
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => isset($services[$id]));
        $container->method('get')->willReturnCallback(static fn (string $id) => $services[$id] ?? null);

        $controller = new SocialCalendarFeedController($feed);
        $controller->setContainer($container);

        return $controller;
    }

    // The feed's own address serves the calendar, built for the request's host and linking back to the calendar's screen, kept by no shared cache
    public function testTheFeedsOwnAddressServesTheCalendar(): void
    {
        $feed = $this->createMock(SocialCalendarFeed::class);
        $feed->method('isToken')->willReturnCallback(static fn (string $token): bool => self::TOKEN === $token);
        $feed->expects($this->once())->method('build')
            ->with('example.org', 'https://example.org/management/social-calendar')
            ->willReturn("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n");

        $response = $this->controller($feed)->feed(Request::create('https://example.org/social/calendar/' . self::TOKEN . '.ics'), self::TOKEN);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame("BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n", $response->getContent());
        $this->assertSame('text/calendar; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertSame('inline; filename="social-calendar.ics"', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));
    }

    // A wrong or withdrawn address answers as a page that does not exist, the feed never built
    public function testAWrongAddressIsNotFound(): void
    {
        $feed = $this->createMock(SocialCalendarFeed::class);
        $feed->method('isToken')->willReturn(false);
        $feed->expects($this->never())->method('build');

        $this->expectException(NotFoundHttpException::class);

        $this->controller($feed)->feed(Request::create('https://example.org/social/calendar/' . str_repeat('b', 64) . '.ics'), str_repeat('b', 64));
    }
}
