<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Repository\SocialPostRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

// The publications calendar as an iCalendar feed (RFC 5545), subscribed to by its secret address from Outlook, Evolution or Google Calendar: one event of a quarter of an hour per post, from two months back to a year ahead, its state before its title, its networks as its categories. The other sites listed in "social-calendar-shared-feeds" follow, their name before their title - unless the feed is asked for its own posts only, as another site reads it (see SocialSharedCalendar)
class SocialCalendarFeed
{
    // The config holding the secret part of the feed's address, empty until the calendar's screen creates it
    public const string TOKEN = 'social-calendar-ics-token';

    public function __construct(
        private readonly SocialPostRepository $postRepository,
        private readonly ConfigServiceInterface $configService,
        private readonly TranslatorInterface $translator,
        private readonly SocialSharedCalendar $sharedCalendar,
    ) {
    }

    // Whether the token given is the feed's own - never for a feed whose address was not created yet
    public function isToken(string $token): bool
    {
        $own = (string) $this->configService->get(self::TOKEN);

        return '' !== $own && hash_equals($own, $token);
    }

    // The whole feed, lines folded and ended as the RFC asks - $own leaving the other sites out
    public function build(string $host, string $calendarUrl, bool $own = false): string
    {
        $now = new \DateTimeImmutable();
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//975L//SocialBundle//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . $this->escape($this->translator->trans('label.social_calendar', [], 'social') . ' - ' . $host),
            // A hint the clients honouring it refresh by, the others keeping their own pace
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H',
        ];
        [$start, $end] = [$now->modify('-2 months'), $now->modify('+1 year')];
        foreach ($this->postRepository->findPlannedBetween($start, $end) as $post) {
            array_push($lines, ...$this->event($post, $host, $calendarUrl, $now));
        }
        foreach ($own ? [] : $this->sharedCalendar->getEvents($start, $end) as $shared) {
            array_push($lines, ...$this->sharedEvent($shared, $now));
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines)) . "\r\n";
    }

    /** @return list<string> */
    private function event(SocialPost $post, string $host, string $calendarUrl, \DateTimeImmutable $now): array
    {
        $state = $this->translator->trans('label.social_calendar_state_' . $post->getState(), [], 'social');
        $networks = array_map(static fn (SocialPostTarget $target): string => $target->getNetwork(), $post->getTargets()->getValues());
        // The back-office calendar on the post's day, its panel open
        $url = $calendarUrl . (str_contains($calendarUrl, '?') ? '&' : '?') . 'date=' . $post->getPlannedAt()->format('Y-m-d') . '&post=' . $post->getId();

        return $this->eventLines(
            'social-post-' . $post->getId() . '@' . $host,
            $post->getPlannedAt(),
            '[' . $state . '] ' . $post->getTitle(),
            implode(', ', array_map(ucfirst(...), $networks)) . "\n\n" . ($post->getText() ?? $post->getUrl()),
            $url,
            $networks,
            $now,
            // A draft is not decided yet: the clients showing it apart do so
            ['STATUS:' . ('draft' === $post->getState() ? 'TENTATIVE' : 'CONFIRMED')],
        );
    }

    // Another site's post, its name before its title and its UID kept, marked as shared so a feed reading this one leaves it out
    /**
     * @param array{site: string, uid: string, start: \DateTimeImmutable, summary: string, url: ?string, networks: list<string>} $shared
     *
     * @return list<string>
     */
    private function sharedEvent(array $shared, \DateTimeImmutable $now): array
    {
        return $this->eventLines($shared['uid'], $shared['start'], '[' . $shared['site'] . '] ' . $shared['summary'], implode(', ', array_map(ucfirst(...), $shared['networks'])), $shared['url'], $shared['networks'], $now, ['X-SOCIAL-SHARED:1']);
    }

    // An event of a quarter of an hour, the link repeated in the description, Outlook and Google showing no URL property
    /**
     * @param list<string> $networks
     * @param list<string> $extra
     *
     * @return list<string>
     */
    private function eventLines(string $uid, \DateTimeImmutable $start, string $summary, string $description, ?string $url, array $networks, \DateTimeImmutable $now, array $extra): array
    {
        $utc = new \DateTimeZone('UTC');
        $start = $start->setTimezone($utc);

        return [
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $now->setTimezone($utc)->format('Ymd\THis\Z'),
            'DTSTART:' . $start->format('Ymd\THis\Z'),
            'DTEND:' . $start->modify('+15 minutes')->format('Ymd\THis\Z'),
            'SUMMARY:' . $this->escape($summary),
            'DESCRIPTION:' . $this->escape(trim(trim($description) . (null === $url ? '' : "\n\n" . $url))),
            ...(null === $url ? [] : ['URL:' . $url]),
            // Each network escaped on its own, the commas between them being the list's
            ...([] === $networks ? [] : ['CATEGORIES:' . implode(',', array_map($this->escape(...), $networks))]),
            ...$extra,
            'TRANSP:TRANSPARENT',
            'END:VEVENT',
        ];
    }

    // A text value's commas, semicolons, backslashes and line breaks escaped
    private function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $text);
    }

    // A line longer than 75 octets carried on by lines starting with a space, never cutting a character in two
    private function fold(string $line): string
    {
        $folded = '';
        $current = '';
        $limit = 75;
        foreach (mb_str_split($line) as $char) {
            if (\strlen($current . $char) > $limit) {
                $folded .= $current . "\r\n ";
                $current = '';
                // The space opening a continuation line counts in its 75
                $limit = 74;
            }
            $current .= $char;
        }

        return $folded . $current;
    }
}
