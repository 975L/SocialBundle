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
use c975L\SocialBundle\Enum\SocialPostStatus;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Service\SocialMediaChecker;
use c975L\SocialBundle\Service\SocialPlanner;
use c975L\SocialBundle\Service\SocialPublisher;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

// The week - or the month - of publications: what went out, and every post still to go out at its planned moment, a draft as well as an approved one. A post is dragged onto another moment to plan it there, its approval untouched; a click opens it, a double click on a coming moment writes a new one there
class SocialCalendarController extends AbstractController
{
    // The dashboard prefixes the AdminRoute name with its own route name (see SocialConnectionsController::ROUTE)
    public const string ROUTE = 'management_social_calendar';

    private const string MOVE_ROUTE = 'management_social_calendar_move';

    private const string PANEL_ROUTE = 'management_social_calendar_post';

    // A drag plans a post that goes out under the site's name: checked against a token, like "Publish"
    private const string CSRF_TOKEN = 'social_calendar';

    // The panel's two other gestures, sent through the same move as a drag
    private const string TO_APPROVE = 'approve';

    private const string TO_UNAPPROVE = 'unapprove';

    // The two views: a week of tall days, opened first, or a month of short ones
    private const string WEEK = 'week';

    private const string MONTH = 'month';

    // How many quarters of an hour a card covers on the grid (a quarter being .75rem, a card 22px)
    private const int CARD_HEIGHT = 2;

    // The hours the week's grid shows when the site sets none
    private const int HOUR_START = 6;

    private const int HOUR_END = 23;

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly SocialPlanner $planner,
        private readonly SocialPublisher $publisher,
        private readonly SocialPostRepository $postRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly SocialMediaChecker $mediaChecker,
        private readonly TranslatorInterface $translator,
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

        return $this->render('@c975LSocial/management/social_calendar.html.twig', [
            'view' => $month ? self::MONTH : self::WEEK,
            'title' => $title,
            // Kept when switching between the two views
            'date' => $anchor->format('Y-m-d'),
            'weekdays' => array_map(fn (int $day): string => $this->format($start->modify('+' . $day . ' days'), 'EEE', $locale), range(0, 6)),
            'weeks' => array_chunk($this->days($start, $end, $month ? $anchor : null), 7),
            'hours' => $month ? [] : $this->hours(),
            'networks' => array_map(static fn (string $network): array => ['name' => $network, 'connected' => \in_array($network, $connected, true)], $this->publisher->getNetworkNames()),
            'connected' => $connected,
            'previous' => $previous->format('Y-m-d'),
            'next' => $next->format('Y-m-d'),
            'move_url' => $this->generateUrl(self::MOVE_ROUTE),
            'panel_url' => $this->generateUrl(self::PANEL_ROUTE),
            // A double click on a coming moment writes a post there, the screen of a new post opening on it
            'new_url' => $this->adminUrlGenerator->unsetAll()->setController(SocialPostCrudController::class)->setAction(Action::NEW)->generateUrl(),
            'series_url' => $this->adminUrlGenerator->unsetAll()->setController(SocialPostCrudController::class)->setAction('generateSeries')->generateUrl(),
            'connections_route' => SocialConnectionsController::ROUTE,
            'token' => self::CSRF_TOKEN,
        ]);
    }

    // The panel a card opens beside the calendar: when the post goes out, the moment to set it to, its approval, its texts - the screen of the post a link away, where they are corrected and rephrased
    #[AdminRoute(path: '/social-calendar/post', name: 'social_calendar_post')]
    public function panel(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $post = $this->postRepository->find($request->query->getInt('id'));
        if (!$post instanceof SocialPost) {
            return new Response(null, Response::HTTP_NOT_FOUND);
        }

        $card = $this->card($post);

        return $this->render('@c975LSocial/management/_social_calendar_panel.html.twig', [
            'post' => $card,
            'state' => $card['state'],
            'targets' => $post->getTargets(),
            'connected' => $this->publisher->getConnectedNetworkNames(),
        ]);
    }

    // A post dropped on a moment - a quarter of an hour of the week, or a day of the month, keeping its time there - planned at it, its approval untouched; or the panel's approval and its withdrawal. A post gone out on every network moves no more
    #[AdminRoute(path: '/social-calendar/move', name: 'social_calendar_move', options: ['methods' => ['POST']])]
    public function move(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN, $request->headers->get('X-CSRF-Token'))) {
            return new Response(null, Response::HTTP_FORBIDDEN);
        }

        $post = $this->postRepository->find($request->request->getInt('post'));
        if (!$post instanceof SocialPost || 'published' === $this->state($post)) {
            return new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $to = $request->request->getString('to');
        // A media a network does not take refuses the approval, its reasons said in the answer
        $problems = self::TO_APPROVE === $to ? $this->mediaChecker->check($post) : [];
        if ([] !== $problems) {
            return new Response($this->problemsText($problems), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (\in_array($to, [self::TO_APPROVE, self::TO_UNAPPROVE], true)) {
            self::TO_APPROVE === $to ? $post->approve() : $post->unapprove();
        } else {
            // A moment already gone would only be sent at once, somewhere the editor did not drop it
            $plannedAt = $this->moment($to, $post->getPlannedAt());
            if (null === $plannedAt || $plannedAt <= new \DateTimeImmutable()) {
                return new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $post->setPlannedAt($plannedAt);
        }
        $this->entityManager->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    // One line per network and problem, the way the post's screen says them
    /** @param array<string, list<TranslatableMessage>> $problems */
    private function problemsText(array $problems): string
    {
        $lines = [];
        foreach ($problems as $network => $messages) {
            foreach ($messages as $message) {
                $lines[] = ucfirst($network) . ' : ' . $message->trans($this->translator);
            }
        }

        return implode("\n", $lines);
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

    // An ISO 8601 moment brought to the server's own time zone, the one the database stores and the planned run goes by. A day alone is a day of the month, the post keeping its time of day there; one with no offset is the panel's field, typed in that time zone and brought to the nearest quarter of an hour - a drop's moment staying exact
    private function moment(string $value, \DateTimeImmutable $current): ?\DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false !== $day) {
            return $day->setTime((int) $current->format('G'), (int) $current->format('i'));
        }

        try {
            $moment = '' === $value ? null : new \DateTimeImmutable($value)->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            return null;
        }

        if (null !== $moment && false !== \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value)) {
            $moment = $this->planner->round($moment);
        }

        return $moment;
    }

    // Every day of the grid with what it holds, in time order: the posts that went out, and the ones still to go out at their moment - $month the month shown, the days around it faded, null for a week, which also gets its quarters of an hour
    /** @return list<array<string, mixed>> */
    private function days(\DateTimeImmutable $start, \DateTimeImmutable $end, ?\DateTimeImmutable $month): array
    {
        $items = $this->publishedItems($start, $end);
        foreach ($this->comingItems($start, $end) as $day => $dayItems) {
            $items[$day] = [...$items[$day] ?? [], ...$dayItems];
        }

        $days = [];
        $today = new \DateTimeImmutable('today');
        for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
            $dayItems = array_values($items[$day->format('Y-m-d')] ?? []);
            usort($dayItems, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
            $days[] = [
                'date' => $day,
                // A day of the month still ahead takes a card dropped on it
                'open' => $day >= $today,
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

    // What is still to go out between the two days, by day: every post not published yet, at its planned moment
    /** @return array<string, list<array<string, mixed>>> */
    private function comingItems(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $items = [];
        foreach ($this->postRepository->findPlannedBetween($start, $end) as $post) {
            if (!$post->isPublished()) {
                $at = $post->getPlannedAt();
                $items[$at->format('Y-m-d')][] = ['kind' => 'planned', 'at' => $at] + $this->card($post);
            }
        }

        return $items;
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
                $items[$publishedAt->format('Y-m-d')][$key] ??= ['kind' => 'published', 'at' => $publishedAt, 'networks' => [], 'state' => 'published'] + $this->card($post);
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
            $lanes[$lane] = $row + self::CARD_HEIGHT;
            $groups[] = ['row' => $row, 'lane' => $lane, 'lanes' => 1, 'items' => $rowItems];
        }

        // Every group as wide as the lanes the day needs, kept simple rather than widened where it is alone
        return array_map(static fn (array $group): array => ['lanes' => max(1, \count($lanes))] + $group, $groups);
    }

    // What a post shows on the calendar, wherever it sits
    /** @return array<string, mixed> */
    private function card(SocialPost $post): array
    {
        return [
            'id' => $post->getId(),
            'title' => $post->getTitle(),
            'image' => $post->getThumbnailUrl(),
            // The networks it still goes out on
            'networks' => $post->getTargets()->filter(static fn (SocialPostTarget $target): bool => $target->isPending())->map(static fn (SocialPostTarget $target): string => $target->getNetwork())->getValues(),
            'planned_at' => $post->getPlannedAt(),
            'state' => $this->state($post),
            'edit_url' => $this->editUrl($post),
        ];
    }

    // What the card's colour says: gone out everywhere, refused somewhere, waiting for its moment, or for a reading
    private function state(SocialPost $post): string
    {
        return match (true) {
            $post->isPublished() => 'published',
            $post->getTargets()->exists(static fn (int $key, SocialPostTarget $target): bool => SocialPostStatus::Failed === $target->getStatus()) => 'failed',
            $post->isApproved() => 'approved',
            default => 'draft',
        };
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
