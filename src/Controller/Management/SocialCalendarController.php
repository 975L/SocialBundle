<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Controller\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Repository\SocialScheduleRepository;
use c975L\SocialBundle\Service\SocialPlanner;
use c975L\SocialBundle\Service\SocialPublisher;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function Symfony\Component\Translation\t;

// The week - or the month - of publications: what went out, and where each approved post falls among the coming slots - computed by SocialPlanner, the very rule the slots send by, so nothing is stored here. A post is dragged onto a slot to plan it there, onto the queue to unplan it, onto the drafts to take it out of the queue; a click opens it
class SocialCalendarController extends AbstractController
{
    // The dashboard prefixes the AdminRoute name with its own route name (see SocialConnectionsController::ROUTE)
    public const string ROUTE = 'management_social_calendar';

    private const string MOVE_ROUTE = 'management_social_calendar_move';

    private const string PREPARE_ROUTE = 'management_social_calendar_prepare';

    private const string PANEL_ROUTE = 'management_social_calendar_post';

    // A drag plans a post that goes out under the site's name: checked against a token, like "Publish"
    private const string CSRF_TOKEN = 'social_calendar';

    // Where a post dragged off the slots lands: the queue keeps it approved, the drafts take it back for a reading
    private const string TO_QUEUE = 'queue';

    private const string TO_DRAFTS = 'drafts';

    // The two views: a week of tall days, opened first, or a month of short ones
    private const string WEEK = 'week';

    private const string MONTH = 'month';

    // What is still to go out between the two days, by day: the drafts planned for a moment, the approved posts planned for theirs, and the coming slots with the post each will send
    /**
     * @param list<SocialPost> $drafts
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function comingItems(\DateTimeImmutable $start, \DateTimeImmutable $end, array $drafts): array
    {
        // A draft planned for a moment goes out only once approved, but holds that moment already: shown there, and its slot not offered for preparing twice
        $plannedDrafts = array_values(array_filter($drafts, static fn (SocialPost $draft): bool => null !== $draft->getPlannedAt()));

        $items = [];
        foreach ($plannedDrafts as $draft) {
            $at = $draft->getPlannedAt();
            if (null !== $at && $at >= $start && $at < $end) {
                $items[$at->format('Y-m-d')][] = ['kind' => 'planned', 'at' => $at, 'slot' => null, 'slot_networks' => [], 'post' => ['sent' => [], 'pinned' => true, 'draft' => true] + $this->card($draft)];
            }
        }

        foreach ($this->planner->project($start, $end, $this->publisher->getConnectedNetworkNames()) as $occurrence) {
            // A slot a planned post stands in for shows that post alone
            if ($occurrence['taken'] || (null !== $occurrence['slot'] && $this->planner->isTaken($plannedDrafts, $occurrence['networks'], $occurrence['at']))) {
                continue;
            }

            $items[$occurrence['at']->format('Y-m-d')][] = [
                'kind' => null === $occurrence['slot'] ? 'planned' : 'slot',
                'at' => $occurrence['at'],
                'slot' => $occurrence['slot'],
                'slot_networks' => $occurrence['networks'],
                'post' => null === $occurrence['post'] ? null : ['sent' => $occurrence['sent'], 'pinned' => null !== $occurrence['post']->getPlannedAt(), 'draft' => false] + $this->card($occurrence['post']),
            ];
        }

        return $items;
    }

    // The hours the week's grid shows when the site sets none
    private const int HOUR_START = 6;

    private const int HOUR_END = 23;

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly SocialPlanner $planner,
        private readonly SocialPublisher $publisher,
        private readonly SocialPostRepository $postRepository,
        private readonly SocialScheduleRepository $scheduleRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    #[AdminRoute(path: '/social-calendar', name: 'social_calendar')]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $locale = $request->getLocale();
        $date = $this->date($request->query->getString('date'));
        $month = self::MONTH === $request->query->getString('view');
        if ($month) {
            // Whole weeks, Monday first, so the grid has no hole at either end
            $anchor = $date->modify('first day of this month');
            $start = $this->monday($anchor);
            $end = $this->monday($anchor->modify('first day of next month')->modify('+6 days'));
            $title = $this->format($anchor, 'LLLL y', $locale);
            [$previous, $next] = [$anchor->modify('-1 month'), $anchor->modify('+1 month')];
        } else {
            $anchor = $start = $this->monday($date);
            $end = $start->modify('+7 days');
            $title = $this->format($start, 'd MMM', $locale) . ' - ' . $this->format($end->modify('-1 day'), 'd MMM y', $locale);
            [$previous, $next] = [$start->modify('-7 days'), $end];
        }

        $connected = $this->publisher->getConnectedNetworkNames();
        $drafts = $this->postRepository->findDrafts();
        $moments = $this->planner->nextMoments($connected);

        return $this->render('@c975LSocial/management/social_calendar.html.twig', [
            'view' => $month ? self::MONTH : self::WEEK,
            'title' => $title,
            // Kept when switching between the two views
            'date' => $anchor->format('Y-m-d'),
            'weekdays' => array_map(fn (int $day): string => $this->format($start->modify('+' . $day . ' days'), 'EEE', $locale), range(0, 6)),
            'weeks' => array_chunk($this->days($start, $end, $month ? $anchor : null, $drafts), 7),
            'hours' => $month ? [] : $this->hours(),
            'drafts' => array_map($this->card(...), $drafts),
            // Each post of the queue with the moment it goes out, the projection's own
            'queue' => array_map(fn (SocialPost $post): array => ['next_at' => $moments[(int) $post->getId()] ?? null] + $this->card($post), array_values(array_filter($this->postRepository->findApproved(), static fn (SocialPost $post): bool => null === $post->getPlannedAt()))),
            'networks' => array_map(static fn (string $network): array => ['name' => $network, 'connected' => \in_array($network, $connected, true)], $this->publisher->getNetworkNames()),
            'connected' => $connected,
            'has_slot' => $this->scheduleRepository->hasEnabled(),
            'previous' => $previous->format('Y-m-d'),
            'next' => $next->format('Y-m-d'),
            'move_url' => $this->generateUrl(self::MOVE_ROUTE),
            'prepare_url' => $this->generateUrl(self::PREPARE_ROUTE),
            'panel_url' => $this->generateUrl(self::PANEL_ROUTE),
            'connections_route' => SocialConnectionsController::ROUTE,
            'token' => self::CSRF_TOKEN,
        ]);
    }

    // The panel a card opens beside the calendar: when the post goes out, the moment to set it to, its texts - the screen of the post a link away, where they are corrected and rephrased
    #[AdminRoute(path: '/social-calendar/post', name: 'social_calendar_post')]
    public function panel(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $post = $this->postRepository->find($request->query->getInt('id'));
        if (!$post instanceof SocialPost) {
            return new Response(null, Response::HTTP_NOT_FOUND);
        }

        $connected = $this->publisher->getConnectedNetworkNames();
        $next = $post->isApproved() && null === $post->getPlannedAt() ? $this->planner->nextMoments($connected)[(int) $post->getId()] ?? null : null;
        $published = !$post->isApproved() && !$post->isApprovable();

        return $this->render('@c975LSocial/management/_social_calendar_panel.html.twig', [
            'post' => $this->card($post),
            'state' => match (true) {
                $published => 'published',
                null !== $post->getPlannedAt() && $post->isApproved() => 'planned',
                $post->isApproved() => 'queued',
                default => 'draft',
            },
            'next' => $next,
            // The moment the field opens on: the one set, the one the queue gives, or the next quarter of an hour
            'plan_at' => $post->getPlannedAt() ?? $next ?? $this->planner->nextQuarter(new \DateTimeImmutable()),
            'targets' => $post->getTargets(),
            'connected' => $connected,
        ]);
    }

    // A post dropped somewhere: onto a moment - a quarter of an hour of the week, a slot of the month - planned and approved, onto the queue approved and unplanned, onto the drafts back to a reading
    #[AdminRoute(path: '/social-calendar/move', name: 'social_calendar_move', options: ['methods' => ['POST']])]
    public function move(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN, $request->headers->get('X-CSRF-Token'))) {
            return new Response(null, Response::HTTP_FORBIDDEN);
        }

        $post = $this->postRepository->find($request->request->getInt('post'));
        $to = $request->request->getString('to');
        $plannedAt = \in_array($to, [self::TO_QUEUE, self::TO_DRAFTS], true) ? null : $this->moment($to);
        // A slot already gone would send nothing: the post would only wait for the next one, somewhere the editor did not drop it
        if (!$post instanceof SocialPost || (null === $plannedAt && !\in_array($to, [self::TO_QUEUE, self::TO_DRAFTS], true)) || (null !== $plannedAt && $plannedAt <= new \DateTimeImmutable())) {
            return new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        self::TO_DRAFTS === $to ? $post->unapprove() : $post->approve();
        $post->setPlannedAt($plannedAt);
        $this->entityManager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    // A free slot clicked: its next content, prepared as a draft planned there, opened for its reading
    #[AdminRoute(path: '/social-calendar/prepare', name: 'social_calendar_prepare', options: ['methods' => ['POST']])]
    public function prepare(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $slot = $this->scheduleRepository->find($request->request->getInt('slot'));
        $at = $this->moment($request->request->getString('at'));

        // A slot already holding a post, from another tab or a second click, opens that one rather than preparing a second for the same moment
        $planned = null === $at ? null : array_find([...$this->postRepository->findDrafts(), ...$this->postRepository->findApproved()], static fn (SocialPost $post): bool => $post->getPlannedAt() == $at);
        if (null !== $planned) {
            $this->addFlash('warning', t('flash.social_calendar_slot_taken', [], 'social'));

            return $this->redirect($this->editUrl($planned));
        }

        $post = null !== $slot && null !== $at && $this->isCsrfTokenValid(self::CSRF_TOKEN, $request->request->getString('_token'))
            ? $this->publisher->prepareForSlot($slot, $at)
            : null;

        if (null === $post) {
            $this->addFlash('warning', t('flash.social_post_nothing', [], 'social'));

            return $this->redirectToRoute(self::ROUTE, ['date' => $at?->format('Y-m-d')]);
        }

        return $this->redirect($this->editUrl($post));
    }

    // The day asked for, today for anything else
    private function date(string $date): \DateTimeImmutable
    {
        $day = 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;

        return false === $day ? new \DateTimeImmutable('today') : $day;
    }

    // The Monday of the week the day falls in
    private function monday(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return $day->modify('-' . ((int) $day->format('N') - 1) . ' days');
    }

    // An ISO 8601 moment brought to the server's own time zone, the one the database stores and the slots run in. One with no offset is the panel's field, typed in that time zone and brought to the nearest quarter of an hour - a drop's moment, a slot's included, staying exact
    private function moment(string $value): ?\DateTimeImmutable
    {
        try {
            $moment = '' === $value ? null : new \DateTimeImmutable($value)->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            return null;
        }

        if (null !== $moment && false !== \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value)) {
            $moment = $moment->setTimestamp((int) round($moment->getTimestamp() / SocialPlanner::QUARTER) * SocialPlanner::QUARTER);
        }

        return $moment;
    }

    // Every day of the grid with what it holds, in time order: the posts that went out, the planned ones at their moment, then the coming slots with the post each will send - $month the month shown, the days around it faded, null for a week, which also gets its quarters of an hour
    /**
     * @param list<SocialPost> $drafts
     *
     * @return list<array<string, mixed>>
     */
    private function days(\DateTimeImmutable $start, \DateTimeImmutable $end, ?\DateTimeImmutable $month, array $drafts): array
    {
        $items = $this->publishedItems($start, $end);
        foreach ($this->comingItems($start, $end, $drafts) as $day => $dayItems) {
            $items[$day] = [...$items[$day] ?? [], ...$dayItems];
        }

        $days = [];
        $today = new \DateTimeImmutable('today');
        for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
            $dayItems = array_values($items[$day->format('Y-m-d')] ?? []);
            usort($dayItems, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
            $days[] = [
                'date' => $day,
                'in_month' => null === $month || $day->format('m') === $month->format('m'),
                'today' => $day == $today,
                'items' => $dayItems,
                // The week's grid: the quarters of an hour a card may be dropped on, and the items by the quarter they stand at
                'cells' => null === $month ? $this->cells($day) : [],
                'rows' => null === $month ? $this->rows($dayItems) : [],
            ];
        }

        return $days;
    }

    // The posts that went out between the two days, by day: one card per post and day, its networks gathered on it
    /** @return array<string, array<string, array<string, mixed>>> */
    private function publishedItems(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $items = [];
        foreach ($this->postRepository->findPublishedBetween($start, $end) as $post) {
            foreach ($post->getTargets() as $target) {
                $publishedAt = $target->getPublishedAt();
                if (null === $publishedAt || $publishedAt < $start || $publishedAt >= $end) {
                    continue;
                }

                $key = $publishedAt->format('Y-m-d') . '#' . $post->getId();
                $items[$publishedAt->format('Y-m-d')][$key] ??= ['kind' => 'published', 'at' => $publishedAt, 'networks' => []] + $this->card($post);
                $items[$publishedAt->format('Y-m-d')][$key]['networks'][] = $target->getNetwork();
            }
        }

        return $items;
    }

    // The hours the week's grid shows, from "social-calendar-hour-start" to "social-calendar-hour-end" - 6 to 23 when unset or the wrong way round
    /** @return array{0: int, 1: int} */
    private function hourRange(): array
    {
        $start = (int) ($this->configService->get('social-calendar-hour-start') ?? self::HOUR_START);
        $end = (int) ($this->configService->get('social-calendar-hour-end') ?? self::HOUR_END);

        return $start >= 0 && $end <= 24 && $start < $end ? [$start, $end] : [self::HOUR_START, self::HOUR_END];
    }

    // The labels down the side of the week's grid, one per hour
    /** @return list<string> */
    private function hours(): array
    {
        [$start, $end] = $this->hourRange();

        return array_map(static fn (int $hour): string => sprintf('%02d:00', $hour), range($start, $end - 1));
    }

    // Every quarter of an hour of the day's grid, the ones gone by taking no card: the planned run would only send it at once
    /** @return list<array{at: \DateTimeImmutable, open: bool}> */
    private function cells(\DateTimeImmutable $day): array
    {
        [$start, $end] = $this->hourRange();
        $now = new \DateTimeImmutable();
        $cells = [];
        for ($minutes = $start * 60; $minutes < $end * 60; $minutes += 15) {
            $at = $day->setTime(intdiv($minutes, 60), $minutes % 60);
            $cells[] = ['at' => $at, 'open' => $at > $now];
        }

        return $cells;
    }

    // The day's items gathered by the quarter of an hour they stand at, counted from the top of the grid - one before or after the hours shown kept at its edge. A card is taller than a quarter of an hour, so groups whose heights overlap are laid side by side in lanes, the way an agenda does, rather than one hiding the other
    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array{row: int, lane: int, lanes: int, items: list<array<string, mixed>>}>
     */
    private function rows(array $items): array
    {
        [$start, $end] = $this->hourRange();
        $byRow = [];
        foreach ($items as $item) {
            $at = $item['at'];
            \assert($at instanceof \DateTimeInterface);
            $quarter = intdiv((int) $at->format('G') * 60 + (int) $at->format('i') - $start * 60, 15);
            $byRow[max(0, min(($end - $start) * 4 - 1, $quarter))][] = $item;
        }
        ksort($byRow);

        // Each lane remembers the first row it is free again from
        $groups = [];
        $lanes = [];
        foreach ($byRow as $row => $rowItems) {
            $lane = 0;
            while (($lanes[$lane] ?? 0) > $row) {
                ++$lane;
            }
            $lanes[$lane] = $row + max(array_map($this->height(...), $rowItems));
            $groups[] = ['row' => $row, 'lane' => $lane, 'lanes' => 1, 'items' => $rowItems];
        }

        // Every group as wide as the lanes the day needs, kept simple rather than widened where it is alone
        return array_map(static fn (array $group): array => ['lanes' => max(1, \count($lanes))] + $group, $groups);
    }

    // How many quarters of an hour an item covers on the grid (a quarter being .75rem, a card 22px): a slot shows its heading and its networks above its card
    /** @param array<string, mixed> $item */
    private function height(array $item): int
    {
        return 'slot' === $item['kind'] ? 5 : 2;
    }

    // What a post shows on the calendar, wherever it sits
    /** @return array<string, mixed> */
    private function card(SocialPost $post): array
    {
        return [
            'id' => $post->getId(),
            'title' => $post->getTitle(),
            'image' => $post->getImageUrl(),
            // What a drop may approve: a slot only takes a post waiting on one of its networks
            'networks' => $post->getTargets()->filter(static fn (SocialPostTarget $target): bool => $target->isPending())->map(static fn (SocialPostTarget $target): string => $target->getNetwork())->getValues(),
            'planned_at' => $post->getPlannedAt(),
            'edit_url' => $this->editUrl($post),
        ];
    }

    private function editUrl(SocialPost $post): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setController(SocialPostCrudController::class)
            ->setAction(Action::EDIT)
            ->setEntityId($post->getId())
            ->generateUrl();
    }

    // A date as the visitor's language writes it
    private function format(\DateTimeImmutable $date, string $pattern, string $locale): string
    {
        return (string) new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, $pattern)->format($date);
    }
}
