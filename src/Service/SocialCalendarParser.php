<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

// Reads back what SocialCalendarFeed writes, and nothing more: no time zone but UTC, no recurrence, no attendee. An event another feed shared (X-SOCIAL-SHARED) is skipped, so two sites listing each other never send each other's posts back
class SocialCalendarParser
{
    // The feed's events, in the order written - an event lacking its UID or a moment read is left out
    /**
     * @return list<array{uid: string, start: \DateTimeImmutable, summary: string, url: ?string, networks: list<string>}>
     *
     * @throws \UnexpectedValueException when the text is no calendar at all
     */
    public function parse(string $ics): array
    {
        // A byte order mark left by a proxy or a file saved with one would hide the header
        $ics = preg_replace('/^\xEF\xBB\xBF/', '', $ics) ?? $ics;

        // Continuation lines joined back to the line they carry on
        $lines = explode("\n", preg_replace('/\r?\n[ \t]/', '', str_replace("\r\n", "\n", $ics)) ?? '');
        if ('BEGIN:VCALENDAR' !== trim($lines[0])) {
            throw new \UnexpectedValueException('Not an iCalendar feed.');
        }

        $events = [];
        $event = null;
        foreach ($lines as $line) {
            [$name, $value] = $this->property($line);
            if ('BEGIN' === $name && 'VEVENT' === $value) {
                $event = [];
            } elseif ('END' === $name && 'VEVENT' === $value) {
                $events[] = $this->event($event ?? []);
                $event = null;
            } elseif (null !== $event) {
                $event[$name] = $value;
            }
        }

        return array_values(array_filter($events));
    }

    // A line's name, its parameters dropped, and its raw value
    /** @return array{0: string, 1: string} */
    private function property(string $line): array
    {
        $colon = strpos($line, ':');
        if (false === $colon) {
            return ['', ''];
        }

        return [strtoupper(strtok(substr($line, 0, $colon), ';') ?: ''), rtrim(substr($line, $colon + 1), "\r")];
    }

    // One event as the calendar reads it, null for one to leave out
    /**
     * @param array<string, string> $properties
     *
     * @return array{uid: string, start: \DateTimeImmutable, summary: string, url: ?string, networks: list<string>}|null
     */
    private function event(array $properties): ?array
    {
        $start = \DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $properties['DTSTART'] ?? '', new \DateTimeZone('UTC'));
        if (isset($properties['X-SOCIAL-SHARED']) || '' === ($properties['UID'] ?? '') || false === $start) {
            return null;
        }

        // A list's commas are the ones left unescaped
        $networks = preg_split('/(?<!\\\\),/', $properties['CATEGORIES'] ?? '') ?: [];

        return [
            'uid' => $properties['UID'],
            'start' => $start,
            'summary' => $this->unescape($properties['SUMMARY'] ?? ''),
            // Shown as a link: an address of the web only
            'url' => preg_match('#^https?://#i', $properties['URL'] ?? '') ? $properties['URL'] : null,
            'networks' => array_values(array_filter(array_map(fn (string $network): string => strtolower($this->unescape($network)), $networks))),
        ];
    }

    // The escaping SocialCalendarFeed::escape() writes, undone
    private function unescape(string $text): string
    {
        return strtr($text, ['\\\\' => '\\', '\n' => "\n", '\N' => "\n", '\,' => ',', '\;' => ';']);
    }
}
