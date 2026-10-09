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

// The publications calendar as an iCalendar feed (RFC 5545), subscribed to by its secret address from Outlook, Evolution or Google Calendar: one event of a quarter of an hour per post, from two months back to a year ahead, its state before its title
class SocialCalendarFeed
{
    // The config holding the secret part of the feed's address, empty until the calendar's screen creates it
    public const string TOKEN = 'social-calendar-ics-token';

    public function __construct(
        private readonly SocialPostRepository $postRepository,
        private readonly ConfigServiceInterface $configService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // Whether the token given is the feed's own - never for a feed whose address was not created yet
    public function isToken(string $token): bool
    {
        $own = (string) $this->configService->get(self::TOKEN);

        return '' !== $own && hash_equals($own, $token);
    }

    // The whole feed, lines folded and ended as the RFC asks
    public function build(string $host, string $calendarUrl): string
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
        foreach ($this->postRepository->findPlannedBetween($now->modify('-2 months'), $now->modify('+1 year')) as $post) {
            array_push($lines, ...$this->event($post, $host, $calendarUrl, $now));
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines)) . "\r\n";
    }

    /** @return list<string> */
    private function event(SocialPost $post, string $host, string $calendarUrl, \DateTimeImmutable $now): array
    {
        $start = $post->getPlannedAt()->setTimezone(new \DateTimeZone('UTC'));
        $state = $this->translator->trans('label.social_calendar_state_' . $post->getState(), [], 'social');
        $networks = implode(', ', array_map(static fn (SocialPostTarget $target): string => ucfirst($target->getNetwork()), $post->getTargets()->getValues()));
        $url = $calendarUrl . (str_contains($calendarUrl, '?') ? '&' : '?') . 'date=' . $post->getPlannedAt()->format('Y-m-d');

        return [
            'BEGIN:VEVENT',
            'UID:social-post-' . $post->getId() . '@' . $host,
            'DTSTAMP:' . $now->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'),
            'DTSTART:' . $start->format('Ymd\THis\Z'),
            'DTEND:' . $start->modify('+15 minutes')->format('Ymd\THis\Z'),
            'SUMMARY:' . $this->escape('[' . $state . '] ' . $post->getTitle()),
            'DESCRIPTION:' . $this->escape(trim($networks . "\n\n" . ($post->getText() ?? $post->getUrl()))),
            'URL:' . $url,
            // A draft is not decided yet: the clients showing it apart do so
            'STATUS:' . ('draft' === $post->getState() ? 'TENTATIVE' : 'CONFIRMED'),
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
