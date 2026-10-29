<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Service\SocialCalendarParser;
use PHPUnit\Framework\TestCase;

// What SocialCalendarFeed writes, read back: folded lines joined, escapes undone, an event shared by another site or lacking its UID or moment left out
class SocialCalendarParserTest extends TestCase
{
    public function testEventsAreReadBack(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'X-WR-CALNAME:Calendrier - run.as',
            'BEGIN:VEVENT',
            'UID:social-post-3@run.as',
            'DTSTART:20261011T080000Z',
            'SUMMARY:[Approuvé] Raccourcir\, mais',
            '  bien\; vraiment',
            'URL:https://run.as/c?date=2026-10-11&post=3',
            'CATEGORIES:bluesky,LinkedIn',
            'END:VEVENT',
            // Another site's, shared in turn
            'BEGIN:VEVENT',
            'UID:social-post-9@edlo.eu',
            'DTSTART:20261012T080000Z',
            'SUMMARY:[edlo.eu] Annonce',
            'X-SOCIAL-SHARED:1',
            'END:VEVENT',
            // A moment in a form the feed never writes
            'BEGIN:VEVENT',
            'UID:social-post-4@run.as',
            'DTSTART;VALUE=DATE:20261013',
            'END:VEVENT',
            // A link that is no address of the web
            'BEGIN:VEVENT',
            'UID:social-post-5@run.as',
            'DTSTART:20261015T080000Z',
            'URL:javascript:alert(1)',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'DTSTART:20261014T080000Z',
            'END:VEVENT',
            'END:VCALENDAR',
        ]) . "\r\n";

        $events = new SocialCalendarParser()->parse($ics);

        $this->assertSame(['social-post-3@run.as', 'social-post-5@run.as'], array_column($events, 'uid'));
        $this->assertNull($events[1]['url']);
        $this->assertSame('2026-10-11T08:00:00+00:00', $events[0]['start']->format('c'));
        $this->assertSame('[Approuvé] Raccourcir, mais bien; vraiment', $events[0]['summary']);
        $this->assertSame('https://run.as/c?date=2026-10-11&post=3', $events[0]['url']);
        $this->assertSame(['bluesky', 'linkedin'], $events[0]['networks']);
    }

    // A feed written before the categories or without a link still reads
    public function testAnEventWithoutLinkOrCategoriesReads(): void
    {
        $events = new SocialCalendarParser()->parse("BEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:a@b\nDTSTART:20261011T080000Z\nEND:VEVENT\nEND:VCALENDAR\n");

        $this->assertNull($events[0]['url']);
        $this->assertSame([], $events[0]['networks']);
        $this->assertSame('', $events[0]['summary']);
    }

    // A feed starting with a byte order mark still reads
    public function testAByteOrderMarkIsIgnored(): void
    {
        $events = new SocialCalendarParser()->parse("\xEF\xBB\xBFBEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:a@b\nDTSTART:20261011T080000Z\nEND:VEVENT\nEND:VCALENDAR\n");

        $this->assertSame('a@b', $events[0]['uid']);
    }

    // An answer that is no calendar - a login page, an error page - is refused, not read as empty
    public function testWhatIsNoCalendarIsRefused(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        new SocialCalendarParser()->parse('<!DOCTYPE html><html></html>');
    }
}
