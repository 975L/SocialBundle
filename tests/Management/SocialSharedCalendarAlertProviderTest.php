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
use c975L\SocialBundle\Management\SocialSharedCalendarAlertProvider;
use c975L\SocialBundle\Service\SocialSharedCalendar;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// Another site's feed unread is said on the dashboard, from the last read - never by reading the feeds
class SocialSharedCalendarAlertProviderTest extends TestCase
{
    /**
     * @param list<array{site: string, reason: string}> $errors
     *
     * @return list<array<string, mixed>>
     */
    private function alerts(array $errors): array
    {
        $sharedCalendar = $this->createMock(SocialSharedCalendar::class);
        $sharedCalendar->method('getKnownErrors')->willReturn($errors);
        $sharedCalendar->expects($this->never())->method('getErrors');
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('ROLE_EDITOR');
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnArgument(0);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $parameters = []): string => $id . json_encode($parameters));

        return new SocialSharedCalendarAlertProvider($sharedCalendar, $configService, $urlGenerator, $translator)->getAlerts();
    }

    public function testEveryFeedReadSaysNothing(): void
    {
        $this->assertSame([], $this->alerts([]));
    }

    // One alert for all the feeds unread, each site and why, opening the calendar
    public function testTheFeedsUnreadAreOneAlert(): void
    {
        $alerts = $this->alerts([['site' => 'run.as', 'reason' => SocialSharedCalendar::UNREACHABLE], ['site' => 'edlo.eu', 'reason' => SocialSharedCalendar::INVALID]]);

        $this->assertCount(1, $alerts);
        $this->assertSame('label.social_calendar_shared_failed{"%count%":2}', $alerts[0]['label']);
        $this->assertSame('label.social_calendar_shared_unreachable{"%site%":"run.as"} - label.social_calendar_shared_invalid{"%site%":"edlo.eu"}', $alerts[0]['description']);
        $this->assertSame(Config::SEVERITY_WARNING, $alerts[0]['severity']);
        $this->assertSame('management_social_calendar', $alerts[0]['url']);
        $this->assertSame('ROLE_EDITOR', $alerts[0]['role']);
    }
}
