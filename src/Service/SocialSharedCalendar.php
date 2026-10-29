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
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

// The other sites publishing on the same accounts, read from their calendar feeds (see SocialCalendarFeed) so one calendar shows them all: one site per entry of "social-calendar-shared-feeds", {"Name": "address of its feed"}. Each feed is asked for its own posts only, all of them at once with a short timeout and kept a few minutes - a feed down or garbled leaves its site out and says so, never breaking the calendar
class SocialSharedCalendar
{
    // The config listing the other sites' feeds - their addresses being their keys, it is sensitive
    public const string FEEDS = 'social-calendar-shared-feeds';

    public const string UNREACHABLE = 'unreachable';
    public const string INVALID = 'invalid';
    // The site answered but turned the address down - its feed's key renewed or withdrawn
    public const string REFUSED = 'refused';
    public const string MALFORMED = 'malformed';

    // How long a feed is waited for, in seconds
    private const int TIMEOUT = 3;
    // How long a feed read is kept, and a failure, retried sooner
    private const int TTL = 600;
    private const int FAILURE_TTL = 120;
    // Where the last read's failures are kept for the dashboard, which never reads a feed itself - one entry per list of feeds
    private const string ERRORS_KEY = 'social_shared_calendar_errors';

    /** @var array{events: list<array{site: string, uid: string, start: \DateTimeImmutable, summary: string, url: ?string, networks: list<string>}>, errors: list<array{site: string, reason: string}>}|null */
    private ?array $read = null;

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
        private readonly SocialCalendarParser $parser,
    ) {
    }

    // Every listed site's posts between the two moments, the site's name on each, earliest first
    /** @return list<array{site: string, uid: string, start: \DateTimeImmutable, summary: string, url: ?string, networks: list<string>}> */
    public function getEvents(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $events = array_values(array_filter($this->read()['events'], static fn (array $event): bool => $event['start'] >= $start && $event['start'] < $end));
        usort($events, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $events;
    }

    // The sites whose feed could not be read this time, and why
    /** @return list<array{site: string, reason: string}> */
    public function getErrors(): array
    {
        return $this->read()['errors'];
    }

    // The failures of the last read, as the calendar or the feed met them - nothing is fetched here
    /** @return list<array{site: string, reason: string}> */
    public function getKnownErrors(): array
    {
        // None left to speak of once the list is emptied
        $feeds = $this->feeds();
        if ([] === $feeds) {
            return [];
        }
        $item = $this->errorsItem($feeds);

        return $item->isHit() ? $item->get() : [];
    }

    // Every feed read once per request, from the cache when it still holds it
    /** @return array{events: list<array{site: string, uid: string, start: \DateTimeImmutable, summary: string, url: ?string, networks: list<string>}>, errors: list<array{site: string, reason: string}>} */
    private function read(): array
    {
        if (null !== $this->read) {
            return $this->read;
        }

        // Every feed the cache lacks asked at once: the client only waits when a body is read, so the timeouts overlap instead of adding up
        $feeds = $this->feeds();
        $items = [];
        $responses = [];
        foreach ($feeds as $index => [, $url]) {
            if (null === $url) {
                continue;
            }
            $items[$index] = $this->cache->getItem('social_shared_calendar_' . hash('xxh128', $url));
            if (!$items[$index]->isHit()) {
                try {
                    $responses[$index] = $this->httpClient->request('GET', $url . (str_contains($url, '?') ? '&' : '?') . 'own=1', ['timeout' => self::TIMEOUT, 'max_duration' => self::TIMEOUT * 2]);
                } catch (ExceptionInterface) {
                    $responses[$index] = null;
                }
            }
        }

        $events = [];
        $errors = [];
        foreach ($feeds as $index => [$site, $url]) {
            if (null === $url) {
                $errors[] = ['site' => $site, 'reason' => self::MALFORMED];
                continue;
            }

            $feed = \array_key_exists($index, $responses) ? $this->fetch($items[$index], $responses[$index]) : $items[$index]->get();
            if (null !== $feed['error']) {
                $errors[] = ['site' => $site, 'reason' => $feed['error']];
                continue;
            }
            foreach ($feed['events'] as $event) {
                $events[] = ['site' => $site] + $event;
            }
        }

        // Kept as long as a failure, so the dashboard never outlives the retry, and only written when the last read changed it
        if ([] !== $feeds && $errors !== $this->getKnownErrors()) {
            $this->cache->save($this->errorsItem($feeds)->set($errors)->expiresAfter(self::FAILURE_TTL));
        }

        return $this->read = ['events' => $events, 'errors' => $errors];
    }

    // The sites listed, each with its feed's address - null for an entry that gives none, or the site's own feed, which would show its posts twice. A site's name is never its address, which holds its key
    /** @return list<array{0: string, 1: ?string}> */
    private function feeds(): array
    {
        $listed = $this->configService->get(self::FEEDS);
        $own = (string) $this->configService->get(SocialCalendarFeed::TOKEN);
        $feeds = [];
        foreach (\is_array($listed) ? $listed : [] as $name => $url) {
            $url = \is_string($url) ? trim($url) : '';
            $valid = preg_match('#^https?://#i', $url) && false !== filter_var($url, \FILTER_VALIDATE_URL) && ('' === $own || !str_contains($url, $own));
            $site = \is_string($name) && '' !== trim($name) ? trim($name) : (string) parse_url($url, \PHP_URL_HOST);
            $feeds[] = ['' === $site ? '#' . (\count($feeds) + 1) : $site, $valid ? $url : null];
        }

        return $feeds;
    }

    // The failures kept for this very list of feeds: a list corrected or a site removed starts afresh
    /** @param list<array{0: string, 1: ?string}> $feeds */
    private function errorsItem(array $feeds): CacheItemInterface
    {
        return $this->cache->getItem(self::ERRORS_KEY . '_' . hash('xxh128', serialize($feeds)));
    }

    // One feed's answer read, its own posts only, or why it could not be - kept, a failure for less long than a read
    /** @return array{events: list<array{uid: string, start: \DateTimeImmutable, summary: string, url: ?string, networks: list<string>}>, error: ?string} */
    private function fetch(CacheItemInterface $item, ?ResponseInterface $response): array
    {
        $error = self::UNREACHABLE;
        if (null !== $response) {
            try {
                $feed = ['events' => $this->parser->parse($response->getContent()), 'error' => null];
            } catch (ClientExceptionInterface) {
                $error = self::REFUSED;
            } catch (ExceptionInterface) {
                $error = self::UNREACHABLE;
            } catch (\UnexpectedValueException) {
                $error = self::INVALID;
            }
        }
        $feed ??= ['events' => [], 'error' => $error];
        $this->cache->save($item->set($feed)->expiresAfter(null === $feed['error'] ? self::TTL : self::FAILURE_TTL));

        return $feed;
    }
}
