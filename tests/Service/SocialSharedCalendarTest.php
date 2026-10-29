<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Service\SocialCalendarFeed;
use c975L\SocialBundle\Service\SocialCalendarParser;
use c975L\SocialBundle\Service\SocialSharedCalendar;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// The other sites' feeds, read for their own posts only, kept a few minutes, a feed down or garbled leaving its site out and saying so
class SocialSharedCalendarTest extends TestCase
{
    private const string OWN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var list<string> */
    private array $requested = [];

    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
    }

    /**
     * @param array<mixed>                $feeds     as ConfigService decodes the json
     * @param array<string, MockResponse> $responses by the address asked
     */
    private function calendar(array $feeds, array $responses): SocialSharedCalendar
    {
        $config = $this->createStub(ConfigServiceInterface::class);
        $config->method('get')->willReturnCallback(static fn (string $key): mixed => [SocialSharedCalendar::FEEDS => $feeds, SocialCalendarFeed::TOKEN => self::OWN][$key] ?? null);
        $httpClient = new MockHttpClient(function (string $method, string $url) use ($responses): MockResponse {
            $this->requested[] = $url;

            return $responses[$url] ?? new MockResponse('', ['http_code' => 404]);
        });

        return new SocialSharedCalendar($config, $httpClient, $this->cache, new SocialCalendarParser());
    }

    private function ics(string $uid, string $start): string
    {
        return "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:" . $uid . "\r\nDTSTART:" . $start . "\r\nSUMMARY:Post\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    // Each listed site's posts, its name on them, its feed asked for its own posts only - in the period, earliest first
    public function testTheListedSitesPostsAreRead(): void
    {
        $calendar = $this->calendar(['run.as' => 'https://run.as/social/calendar/b.ics', ' 975l.com ' => ' https://975l.com/social/calendar/c.ics?x=1 '], [
            'https://run.as/social/calendar/b.ics?own=1' => new MockResponse($this->ics('3@run.as', '20261012T080000Z')),
            'https://975l.com/social/calendar/c.ics?x=1&own=1' => new MockResponse($this->ics('5@975l.com', '20261011T080000Z')),
        ]);

        $events = $calendar->getEvents(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-11-01'));

        $this->assertSame([['975l.com', '5@975l.com'], ['run.as', '3@run.as']], array_map(static fn (array $event): array => [$event['site'], $event['uid']], $events));
        $this->assertSame([], $calendar->getErrors());
        $this->assertSame([], $calendar->getEvents(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-12-01')));
    }

    // A feed down, an address turned down, an answer that is no calendar, an entry without an address or giving this site's own feed: each said by its name - never its address, which holds its key - never thrown, the others still read
    public function testAFeedUnreadIsSaidAndTheOthersStillRead(): void
    {
        $feeds = [
            'Down' => 'https://down.example/social/calendar/d.ics',
            'Garbled' => 'https://garbled.example/social/calendar/g.ics',
            'Nothing' => ['https://nothing.example'],
            'Ourselves' => 'https://example.org/social/calendar/' . self::OWN . '.ics',
            'run.as' => 'https://run.as/social/calendar/b.ics',
            // Listed with no name: its host stands for it - its key renewed there, the address answers 404
            0 => 'javascript:alert(1)',
            1 => 'https://unnamed.example/social/calendar/u.ics',
        ];
        $calendar = $this->calendar($feeds, [
            'https://down.example/social/calendar/d.ics?own=1' => new MockResponse('', ['error' => 'Timeout']),
            'https://garbled.example/social/calendar/g.ics?own=1' => new MockResponse('<html></html>'),
            'https://run.as/social/calendar/b.ics?own=1' => new MockResponse($this->ics('3@run.as', '20261012T080000Z')),
        ]);

        $this->assertCount(1, $calendar->getEvents(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-11-01')));
        $this->assertSame([
            ['site' => 'Down', 'reason' => SocialSharedCalendar::UNREACHABLE],
            ['site' => 'Garbled', 'reason' => SocialSharedCalendar::INVALID],
            ['site' => 'Nothing', 'reason' => SocialSharedCalendar::MALFORMED],
            ['site' => 'Ourselves', 'reason' => SocialSharedCalendar::MALFORMED],
            ['site' => '#6', 'reason' => SocialSharedCalendar::MALFORMED],
            ['site' => 'unnamed.example', 'reason' => SocialSharedCalendar::REFUSED],
        ], $calendar->getErrors());
        $this->assertNotContains('https://example.org/social/calendar/' . self::OWN . '.ics?own=1', $this->requested);

        // The dashboard reads the failures the last read met, fetching nothing itself - none once the list is corrected
        $this->requested = [];
        $this->assertCount(6, $this->calendar($feeds, [])->getKnownErrors());
        $this->assertSame([], $this->calendar(['Down' => 'https://down.example/social/calendar/d.ics'], [])->getKnownErrors());
        $this->assertSame([], $this->requested);
    }

    // A feed read is kept: the calendar and the feed of the next requests ask it nothing more
    public function testAFeedReadIsKept(): void
    {
        $responses = ['https://run.as/social/calendar/b.ics?own=1' => new MockResponse($this->ics('3@run.as', '20261012T080000Z'))];
        $this->calendar(['run.as' => 'https://run.as/social/calendar/b.ics'], $responses)->getErrors();
        $this->calendar(['run.as' => 'https://run.as/social/calendar/b.ics'], $responses)->getEvents(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-11-01'));

        $this->assertCount(1, $this->requested);
    }

    // No site listed: nothing read, no failure kept for the dashboard
    public function testNoSiteListedReadsNothing(): void
    {
        $calendar = $this->calendar([], []);

        $this->assertSame([], $calendar->getEvents(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-11-01')));
        $this->assertSame([], $calendar->getKnownErrors());
        $this->assertSame([], $this->requested);
    }
}
