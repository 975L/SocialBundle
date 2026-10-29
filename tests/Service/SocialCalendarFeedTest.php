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
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Service\SocialCalendarFeed;
use c975L\SocialBundle\Service\SocialCalendarParser;
use c975L\SocialBundle\Service\SocialSharedCalendar;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class SocialCalendarFeedTest extends TestCase
{
    private function feed(array $posts = [], ?string $token = null, array $shared = []): SocialCalendarFeed
    {
        $repository = $this->createStub(SocialPostRepository::class);
        $repository->method('findPlannedBetween')->willReturn($posts);
        $config = $this->createStub(ConfigServiceInterface::class);
        $config->method('get')->willReturn($token);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => ['label.social_calendar_state_draft' => 'Brouillon', 'label.social_calendar' => 'Calendrier'][$id] ?? $id);

        $sharedCalendar = $this->createStub(SocialSharedCalendar::class);
        $sharedCalendar->method('getEvents')->willReturn($shared);

        return new SocialCalendarFeed($repository, $config, $translator, $sharedCalendar);
    }

    // One event per post at its moment in UTC, its state before its title, its networks and text in its description, a draft tentative
    public function testEachPostIsAnEventAtItsMoment(): void
    {
        $post = new SocialPost(SocialPost::SOURCE_MANUAL, 'abc', '', '', null, new \DateTimeImmutable('2026-10-10 21:00', new \DateTimeZone('Europe/Paris')));
        $post->setText("Photo du soir, en noir; et blanc\n" . str_repeat('é', 60));
        new \ReflectionProperty(SocialPost::class, 'id')->setValue($post, 7);
        new SocialPostTarget($post, 'bluesky', 'Text');

        $ics = $this->feed([$post])->build('example.org', 'https://example.org/management/social-calendar');

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString("UID:social-post-7@example.org\r\n", $ics);
        $this->assertStringContainsString("DTSTART:20261010T190000Z\r\nDTEND:20261010T191500Z\r\n", $ics);
        $this->assertStringContainsString('SUMMARY:[Brouillon] Photo du soir\, en noir\; et blanc', $ics);
        $this->assertStringContainsString("STATUS:TENTATIVE\r\n", $ics);
        $this->assertStringContainsString("URL:https://example.org/management/social-calendar?date=2026-10-10&post=7\r\n", $ics);
        $this->assertStringContainsString("CATEGORIES:bluesky\r\n", $ics);

        // The link also closes the description, unfolded
        $this->assertStringContainsString('\nhttps://example.org/management/social-calendar?date=2026-10-10&post=7', str_replace("\r\n ", '', $ics));

        // Folded at 75 octets, a character never cut in two
        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, \strlen($line));
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'));
        }
    }

    // Only the feed's own token opens it, never one of a feed not created yet
    public function testOnlyTheFeedsOwnTokenOpensIt(): void
    {
        $token = str_repeat('a', 64);

        $this->assertTrue($this->feed(token: $token)->isToken($token));
        $this->assertFalse($this->feed(token: $token)->isToken(str_repeat('b', 64)));
        $this->assertFalse($this->feed()->isToken(''));
    }

    // The other sites' posts follow, their site's name before their title, their UID kept and marked as shared - none when another site asks for the feed's own posts only
    public function testTheOtherSitesPostsFollowUnlessOwnIsAsked(): void
    {
        $shared = [['site' => 'run.as', 'uid' => 'social-post-3@run.as', 'start' => new \DateTimeImmutable('2026-10-11 08:00', new \DateTimeZone('UTC')), 'summary' => '[Approuvé] Raccourcir', 'url' => 'https://run.as/c?post=3', 'networks' => ['bluesky', 'linkedin']]];
        $feed = $this->feed(shared: $shared);

        $ics = $feed->build('example.org', 'https://example.org/c');
        $this->assertStringContainsString("UID:social-post-3@run.as\r\nDTSTAMP:", $ics);
        $this->assertStringContainsString("SUMMARY:[run.as] [Approuvé] Raccourcir\r\n", $ics);
        $this->assertStringContainsString("CATEGORIES:bluesky,linkedin\r\n", $ics);
        $this->assertStringContainsString("X-SOCIAL-SHARED:1\r\n", $ics);

        $this->assertStringNotContainsString('run.as', $feed->build('example.org', 'https://example.org/c', true));
    }

    // What the feed writes, the parser reads back - a shared event left out, so two sites listing each other never loop
    public function testTheParserReadsBackWhatTheFeedWrites(): void
    {
        $post = new SocialPost(SocialPost::SOURCE_MANUAL, 'abc', '', '', null, new \DateTimeImmutable('2026-10-10 21:00', new \DateTimeZone('Europe/Paris')));
        $post->setText('Photo du soir, en noir; et blanc ' . str_repeat('é', 60));
        new \ReflectionProperty(SocialPost::class, 'id')->setValue($post, 7);
        new SocialPostTarget($post, 'bluesky', 'Text');
        $shared = [['site' => 'run.as', 'uid' => 'social-post-3@run.as', 'start' => new \DateTimeImmutable('2026-10-11 08:00', new \DateTimeZone('UTC')), 'summary' => 'Raccourcir', 'url' => null, 'networks' => []]];

        $events = new SocialCalendarParser()->parse($this->feed([$post], shared: $shared)->build('example.org', 'https://example.org/c'));

        $this->assertCount(1, $events);
        $this->assertSame('social-post-7@example.org', $events[0]['uid']);
        $this->assertSame('2026-10-10T19:00:00+00:00', $events[0]['start']->format('c'));
        $this->assertSame('[Brouillon] ' . $post->getTitle(), $events[0]['summary']);
        $this->assertStringContainsString('noir; et blanc', $events[0]['summary']);
        $this->assertSame('https://example.org/c?date=2026-10-10&post=7', $events[0]['url']);
        $this->assertSame(['bluesky'], $events[0]['networks']);
    }
}
