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
use c975L\SocialBundle\Controller\SocialCalendarFeedController;
use c975L\SocialBundle\Entity\SocialMedia;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Service\ConfigValueWriter;
use c975L\SocialBundle\Service\SocialCalendarFeed;
use c975L\SocialBundle\Service\SocialMediaChecker;
use c975L\SocialBundle\Service\SocialMediaPicker;
use c975L\SocialBundle\Service\SocialPlanner;
use c975L\SocialBundle\Service\SocialPostApprover;
use c975L\SocialBundle\Service\SocialPostDuplicator;
use c975L\SocialBundle\Service\SocialPublisher;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

// The week - or the day, or the month - of publications: what went out, and every post still to go out at its planned moment, a draft as well as an approved one. A post is dragged onto another moment to plan it there, its approval untouched; a click opens it, where its whole series may be approved too; a double click on a coming moment writes a new one there
class SocialCalendarController extends AbstractController
{
    // The dashboard prefixes the AdminRoute name with its own route name (see SocialConnectionsController::ROUTE)
    public const string ROUTE = 'management_social_calendar';

    // Carried by the post's screens opened from here, the view they were opened from, to which they come back once saved (see SocialPostCrudController::getRedirectResponseAfterSave())
    public const string FROM = 'calendar';

    private const string MOVE_ROUTE = 'management_social_calendar_move';

    private const string PANEL_ROUTE = 'management_social_calendar_post';

    private const string MEDIAS_ROUTE = 'management_social_calendar_medias';

    private const string TEXTS_ROUTE = 'management_social_calendar_texts';

    private const string UPLOAD_ROUTE = 'management_social_calendar_upload';

    private const string NETWORKS_ROUTE = 'management_social_calendar_networks';

    private const string DUPLICATE_ROUTE = 'management_social_calendar_duplicate';

    private const string FEED_TOKEN_ROUTE = 'management_social_calendar_feed_token';

    // A drag plans a post that goes out under the site's name: checked against a token, like "Publish"
    private const string CSRF_TOKEN = 'social_calendar';

    // The panel's two other gestures, sent through the same move as a drag
    private const string TO_APPROVE = 'approve';

    private const string TO_UNAPPROVE = 'unapprove';

    // Every coming draft of the post's series approved at once
    private const string TO_APPROVE_SERIES = 'approve-series';

    // The three views: a week of tall days, opened first, a single day the same way, or a month of short ones
    public const string WEEK = 'week';

    public const string DAY = 'day';

    public const string MONTH = 'month';

    public const array VIEWS = [self::WEEK, self::DAY, self::MONTH];

    // How many quarters of an hour a card covers on the grid (a quarter being 1.25rem, a card 38px)
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
        private readonly SocialMediaPicker $mediaPicker,
        private readonly SocialPostApprover $approver,
        private readonly SocialPostDuplicator $duplicator,
    ) {
    }

    #[AdminRoute(path: '/social-calendar', name: 'social_calendar')]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $locale = $request->getLocale();
        $date = $this->date($request->query->getString('date'));
        $view = $this->view($request);
        $month = self::MONTH === $view;
        if ($month) {
            // Whole weeks, Monday first, so the grid has no hole at either end
            $anchor = $date->modify('first day of this month');
            $start = $this->monday($anchor);
            $end = $this->monday($anchor->modify('first day of next month')->modify('+6 days'));
            $title = $this->format($anchor, 'LLLL y', $locale);
            [$previous, $next] = [$anchor->modify('-1 month'), $anchor->modify('+1 month')];
        } elseif (self::DAY === $view) {
            $anchor = $start = $date;
            $end = $start->modify('+1 day');
            $title = $this->format($start, 'EEEE d MMMM y', $locale);
            [$previous, $next] = [$start->modify('-1 day'), $end];
        } else {
            $anchor = $start = $this->monday($date);
            $end = $start->modify('+7 days');
            $title = $this->format($start, 'd MMM', $locale) . ' - ' . $this->format($end->modify('-1 day'), 'd MMM y', $locale);
            [$previous, $next] = [$start->modify('-7 days'), $end];
        }

        $connected = $this->publisher->getConnectedNetworkNames();
        $today = new \DateTimeImmutable('today');

        return $this->render('@c975LSocial/management/social_calendar.html.twig', [
            'view' => $view,
            'title' => $title,
            // Kept when switching between the three views
            'date' => $anchor->format('Y-m-d'),
            // The day the "Day" view opens on: today when it is shown, the period's first day otherwise
            'day_date' => ($today >= $start && $today < $end ? $today : $anchor)->format('Y-m-d'),
            'weekdays' => array_map(fn (int $day): string => $this->format($start->modify('+' . $day . ' days'), 'EEE', $locale), range(0, 6)),
            'weeks' => array_chunk($this->days($start, $end, $month ? $anchor : null, $view), 7),
            'hours' => $month ? [] : $this->hours(),
            'networks' => array_map(static fn (string $network): array => ['name' => $network, 'connected' => \in_array($network, $connected, true)], $this->publisher->getNetworkNames()),
            'connected' => $connected,
            'previous' => $previous->format('Y-m-d'),
            'next' => $next->format('Y-m-d'),
            'move_url' => $this->generateUrl(self::MOVE_ROUTE),
            // The panel's own addresses carry the view, which the post's screens it links to come back to
            'panel_url' => $this->generateUrl(self::PANEL_ROUTE, ['view' => $view]),
            'medias_url' => $this->generateUrl(self::MEDIAS_ROUTE, ['view' => $view]),
            'upload_url' => $this->generateUrl(self::UPLOAD_ROUTE, ['view' => $view]),
            'texts_url' => $this->generateUrl(self::TEXTS_ROUTE, ['view' => $view]),
            'networks_url' => $this->generateUrl(self::NETWORKS_ROUTE, ['view' => $view]),
            'duplicate_url' => $this->generateUrl(self::DUPLICATE_ROUTE, ['view' => $view]),
            // The post whose panel opens with the page, a copy just made
            'open_id' => $request->query->getInt('post'),
            // A double click on a coming moment writes a post there, the screen of a new post opening on it
            'new_url' => $this->adminUrlGenerator->unsetAll()->setController(SocialPostCrudController::class)->setAction(Action::NEW)->set(self::FROM, $view)->generateUrl(),
            'series_url' => $this->adminUrlGenerator->unsetAll()->setController(SocialPostCrudController::class)->setAction('generateSeries')->generateUrl(),
            'connections_route' => SocialConnectionsController::ROUTE,
            'token' => self::CSRF_TOKEN,
            'feed_url' => $this->feedUrl(),
            'feed_token_url' => $this->generateUrl(self::FEED_TOKEN_ROUTE),
        ]);
    }

    // Creates the feed's secret address, or replaces it - the former one then answering nothing, for whoever it was given to
    #[AdminRoute(path: '/social-calendar/feed', name: 'social_calendar_feed_token', options: ['methods' => ['POST']])]
    public function feedToken(Request $request, ConfigValueWriter $configWriter): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN, $request->request->getString('token'))) {
            $configWriter->write([SocialCalendarFeed::TOKEN => bin2hex(random_bytes(32))]);
            $this->addFlash('success', new TranslatableMessage('flash.social_calendar_feed_created', [], 'social'));
        }

        return $this->redirectToRoute(self::ROUTE);
    }

    // The feed's address to subscribe to, null until it is created
    private function feedUrl(): ?string
    {
        $token = (string) $this->configService->get(SocialCalendarFeed::TOKEN);

        return '' === $token ? null : $this->generateUrl(SocialCalendarFeedController::ROUTE, ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    // The panel a card opens beside the calendar: when the post goes out, the moment to set it to, its approval, its medias, its texts - the screen of the post a link away, where they are corrected and rephrased
    #[AdminRoute(path: '/social-calendar/post', name: 'social_calendar_post')]
    public function panel(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $post = $this->postRepository->find($request->query->getInt('id'));
        if (!$post instanceof SocialPost) {
            return new Response(null, Response::HTTP_NOT_FOUND);
        }

        return $this->renderPanel($post, $this->view($request));
    }

    // The panel's medias put in the order given, one of them taken off the post first when asked - its file going with it, and the content it was picked from freed. Answers the panel as it now reads; a post gone out on a network keeps what it went out with
    #[AdminRoute(path: '/social-calendar/medias', name: 'social_calendar_medias', options: ['methods' => ['POST']])]
    public function medias(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN, $request->headers->get('X-CSRF-Token'))) {
            return new Response(null, Response::HTTP_FORBIDDEN);
        }

        $post = $this->postRepository->find($request->request->getInt('post'));
        if (!$post instanceof SocialPost || $post->hasGoneOut()) {
            return new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $remove = (int) $request->request->get('remove');
        $order = array_flip(array_map(intval(...), $request->request->all('medias')));
        $kept = [];
        foreach ($post->getMedias()->toArray() as $media) {
            if ($remove === $media->getId()) {
                $post->removeMedia($media);
            } else {
                $kept[] = $media;
            }
        }

        // A media the panel did not name - added since it was read - goes after the others, in the order it had
        usort($kept, static fn (SocialMedia $a, SocialMedia $b): int => [$order[$a->getId()] ?? \PHP_INT_MAX, $a->getPosition()] <=> [$order[$b->getId()] ?? \PHP_INT_MAX, $b->getPosition()]);
        foreach ($kept as $position => $media) {
            $media->setPosition($position);
        }
        $post->sortMedias();
        $this->entityManager->flush();

        return $this->renderPanel($post, $this->view($request));
    }

    // The pictures and videos dropped on the panel, or picked from its zone, added after the post's medias as files of its own - Vich storing each one as the post's screen does (see SocialMediaUploadListener). A file of another type, or one the server did not receive whole, refuses the whole drop, said in the answer; a post gone out on a network keeps what it went out with
    #[AdminRoute(path: '/social-calendar/upload', name: 'social_calendar_upload', options: ['methods' => ['POST']])]
    public function upload(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN, $request->headers->get('X-CSRF-Token'))) {
            return new Response(null, Response::HTTP_FORBIDDEN);
        }

        $post = $this->postRepository->find($request->request->getInt('post'));
        $files = array_filter($request->files->all('files'), static fn (mixed $file): bool => $file instanceof UploadedFile);
        if (!$post instanceof SocialPost || $post->hasGoneOut() || [] === $files) {
            return new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Every file checked before any is kept, the drop going in whole or not at all
        foreach ($files as $file) {
            if (!$file->isValid() || !\in_array($file->getMimeType(), SocialMedia::ACCEPTED_TYPES, true)) {
                return new Response($this->translator->trans('label.social_calendar_upload_refused', ['%name%' => $file->getClientOriginalName()], 'social'), Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $position = $post->getMedias()->count();
        foreach ($files as $file) {
            $post->addMedia(new SocialMedia($post)->setFile($file)->setPosition($position++));
        }
        $this->entityManager->flush();

        return $this->renderPanel($post, $this->view($request), problems: $this->mediaChecker->check($post));
    }

    // The panel's texts saved, each network's own - one gone out on its network keeping what it went out with. An empty text, or one longer than its network takes, refuses the whole save, said in the answer
    #[AdminRoute(path: '/social-calendar/texts', name: 'social_calendar_texts', options: ['methods' => ['POST']])]
    public function texts(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN, $request->headers->get('X-CSRF-Token'))) {
            return new Response(null, Response::HTTP_FORBIDDEN);
        }

        $post = $this->postRepository->find($request->request->getInt('post'));
        if (!$post instanceof SocialPost) {
            return new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Every text checked before any is set, so a refusal saves none
        $texts = $request->request->all('texts');
        $changed = [];
        $problems = [];
        foreach ($post->getTargets() as $target) {
            // A text not sent, or not sent as one, is left as it is
            if (!$target->isPending() || !\is_string($texts[(int) $target->getId()] ?? null)) {
                continue;
            }

            $text = trim($texts[(int) $target->getId()]);
            $maxLength = $this->publisher->getMaxLength($target->getNetwork());
            if ('' === $text || (null !== $maxLength && mb_strlen($text) > $maxLength)) {
                $problems[] = $this->problemText($target->getNetwork(), $this->translator->trans('' === $text ? 'label.social_calendar_text_empty' : 'label.social_post_text_help', ['%max%' => $maxLength], 'social'));
            }
            $changed[] = [$target, $text];
        }

        if ([] !== $problems) {
            return new Response(implode("\n", $problems), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        foreach ($changed as [$target, $text]) {
            $target->setText($text);
        }
        $this->entityManager->flush();

        return $this->renderPanel($post, $this->view($request), true);
    }

    // The networks ticked in the panel, among the connected ones, written the way the post's screen writes them: a network unticked drops its text, one ticked gets its text written - a network not connected left as it was, the panel not offering it. A post gone out anywhere keeps its networks
    #[AdminRoute(path: '/social-calendar/networks', name: 'social_calendar_networks', options: ['methods' => ['POST']])]
    public function networks(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN, $request->headers->get('X-CSRF-Token'))) {
            return new Response(null, Response::HTTP_FORBIDDEN);
        }

        $post = $this->postRepository->find($request->request->getInt('post'));
        if (!$post instanceof SocialPost || $post->hasGoneOut()) {
            return new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $connected = $this->publisher->getConnectedNetworkNames();
        $ticked = array_intersect(array_filter($request->request->all('networks'), \is_string(...)), $connected);
        $post->setNetworks(array_values(array_unique([...$ticked, ...array_diff($post->getNetworks(), $connected)])));
        $this->publisher->addTargets($post, $post->takeAddedNetworks());
        $this->entityManager->flush();

        return $this->renderPanel($post, $this->view($request), problems: $this->mediaChecker->check($post));
    }

    // The post written again the day after, a draft on the networks still connected, its medias its own - answered by the calendar's address on the copy's day, its panel opening there
    #[AdminRoute(path: '/social-calendar/duplicate', name: 'social_calendar_duplicate', options: ['methods' => ['POST']])]
    public function duplicate(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN, $request->headers->get('X-CSRF-Token'))) {
            return new Response(null, Response::HTTP_FORBIDDEN);
        }

        $post = $this->postRepository->find($request->request->getInt('post'));
        if (!$post instanceof SocialPost) {
            return new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $copy = $this->duplicator->duplicate($post, $this->publisher->getConnectedNetworkNames());
        $this->entityManager->persist($copy);
        $this->entityManager->flush();

        return new Response($this->generateUrl(self::ROUTE, ['view' => $this->view($request), 'date' => $copy->getPlannedAt()->format('Y-m-d'), 'post' => $copy->getId()]));
    }

    // The panel as it now reads, answered to a card opened and to its medias or its texts changed alike - $view the calendar's, which the post's screens it links to come back to, $textsSaved saying the texts just were, $problems what a network would refuse in its medias, warned as the post's screen does
    /** @param array<string, list<TranslatableMessage>> $problems */
    private function renderPanel(SocialPost $post, string $view, bool $textsSaved = false, array $problems = []): Response
    {
        $card = $this->card($post, $view);

        return $this->render('@c975LSocial/management/_social_calendar_panel.html.twig', [
            'post' => $card,
            'state' => $card['state'],
            'medias' => $post->getMedias(),
            'editable' => !$post->hasGoneOut(),
            // Each network's limit, the panel's text fields stopping there as the post's screen does
            'max_lengths' => array_map($this->publisher->getMaxLength(...), $post->getTargets()->map(static fn (SocialPostTarget $target): string => $target->getNetwork())->toArray()),
            'targets' => $post->getTargets(),
            'networks' => $post->getNetworks(),
            'texts_saved' => $textsSaved,
            'media_problems' => $this->problemsText($problems),
            'series_drafts' => null === $post->getSeries() ? 0 : $this->postRepository->countComingDraftsBySeries($post->getSeries(), new \DateTimeImmutable()),
            'connected' => $this->publisher->getConnectedNetworkNames(),
            // The networks offered to tick, the connected ones in the site's order
            'offered_networks' => array_values(array_intersect($this->publisher->getNetworkNames(), $this->publisher->getConnectedNetworkNames())),
            // What the panel's drop zone takes, its file picker offering these alone
            'accepted_types' => implode(',', SocialMedia::ACCEPTED_TYPES),
            'pick_media_url' => $this->mediaPicker->hasLibraries() ? $this->postUrl($post, 'pickMedia', $view) : null,
            'change_content_url' => null === $this->publisher->browsableSourceOf($post) ? null : $this->postUrl($post, 'changeContent', $view),
        ]);
    }

    // A post dropped on a moment - a quarter of an hour of the week, or a day of the month, keeping its time there - planned at it, its approval untouched; or the panel's approval, its withdrawal, and the approval of its series. A post gone out on every network moves no more
    #[AdminRoute(path: '/social-calendar/move', name: 'social_calendar_move', options: ['methods' => ['POST']])]
    public function move(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN, $request->headers->get('X-CSRF-Token'))) {
            return new Response(null, Response::HTTP_FORBIDDEN);
        }

        $post = $this->postRepository->find($request->request->getInt('post'));
        $to = $request->request->getString('to');

        // Offered on any post of the series, one gone out as well
        if ($post instanceof SocialPost && self::TO_APPROVE_SERIES === $to) {
            return $this->approveSeries($post);
        }

        if (!$post instanceof SocialPost || 'published' === $post->getState()) {
            return new Response(null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

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

    // The series' coming drafts approved, each at its own moment - one whose medias a network does not take left a draft, its reasons said under its title
    private function approveSeries(SocialPost $post): Response
    {
        $refused = array_map(fn (array $refusal): string => $refusal['post']->getTitle() . "\n" . $this->problemsText($refusal['problems']), $this->approver->approveAll($this->seriesDrafts($post)));

        return [] === $refused ? new Response(null, Response::HTTP_NO_CONTENT) : new Response(implode("\n\n", $refused), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    // The drafts of the post's series still to come, a moment gone being left alone: approved, it would go out at once. A post refused by a network is not one, retried by its own "Approve" only (see SocialPostRepository::countComingDraftsBySeries(), the panel's count)
    /** @return list<SocialPost> */
    private function seriesDrafts(SocialPost $post): array
    {
        $series = $post->getSeries();
        if (null === $series) {
            return [];
        }

        return array_values(array_filter($this->postRepository->findComingBySeries($series, new \DateTimeImmutable()), static fn (SocialPost $draft): bool => 'draft' === $draft->getState()));
    }

    // One line per network and problem, the way the post's screen says them
    /** @param array<string, list<TranslatableMessage>> $problems */
    private function problemsText(array $problems): string
    {
        $lines = [];
        foreach ($problems as $network => $messages) {
            foreach ($messages as $message) {
                $lines[] = $this->problemText($network, $message->trans($this->translator));
            }
        }

        return implode("\n", $lines);
    }

    // A network's problem, written the way the visitor's language puts a colon
    private function problemText(string $network, string $reason): string
    {
        return $this->translator->trans('label.social_calendar_text_refused', ['%network%' => ucfirst($network), '%reason%' => $reason], 'social');
    }

    // The view asked for, the week for anything else
    private function view(Request $request): string
    {
        $view = $request->query->getString('view');

        return \in_array($view, self::VIEWS, true) ? $view : self::WEEK;
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

    // Every day of the grid with what it holds, in time order: the posts that went out, and the ones still to go out at their moment - $month the month shown, the days around it faded, null for a week, which also gets its quarters of an hour. $view is the one shown, which a card's screen comes back to
    /** @return list<array<string, mixed>> */
    private function days(\DateTimeImmutable $start, \DateTimeImmutable $end, ?\DateTimeImmutable $month, string $view = self::WEEK): array
    {
        $items = $this->publishedItems($start, $end, null !== $month, $view);
        foreach ($this->comingItems($start, $end, $view) as $day => $dayItems) {
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
    private function comingItems(\DateTimeImmutable $start, \DateTimeImmutable $end, string $view): array
    {
        $items = [];
        foreach ($this->postRepository->findPlannedBetween($start, $end) as $post) {
            if (!$post->isPublished()) {
                $at = $post->getPlannedAt();
                $items[$at->format('Y-m-d')][] = ['kind' => 'planned', 'at' => $at] + $this->card($post, $view);
            }
        }

        return $items;
    }

    // The posts that went out between the two days, by day: one card per post and run, its networks gathered on it - a network sent more than a quarter of an hour after the card's first one getting its own. $byDay is the month, whose day holds one card per post
    /** @return array<string, array<string, array<string, mixed>>> */
    private function publishedItems(\DateTimeImmutable $start, \DateTimeImmutable $end, bool $byDay = false, string $view = self::WEEK): array
    {
        $items = [];
        foreach ($this->postRepository->findPublishedBetween($start, $end) as $post) {
            // The post's networks gone out in the period, the earliest first
            $targets = array_filter($post->getTargets()->toArray(), static fn (SocialPostTarget $target): bool => null !== $target->getPublishedAt() && $target->getPublishedAt() >= $start && $target->getPublishedAt() < $end);
            usort($targets, static fn (SocialPostTarget $a, SocialPostTarget $b): int => $a->getPublishedAt() <=> $b->getPublishedAt());

            $first = null;
            foreach ($targets as $target) {
                $publishedAt = $target->getPublishedAt();
                if (null === $first || $byDay || $publishedAt->getTimestamp() - $first->getTimestamp() > SocialPlanner::QUARTER) {
                    $first = $publishedAt;
                }

                $key = ($byDay ? '' : $first->format('H:i:s')) . '#' . $post->getId();
                $items[$first->format('Y-m-d')][$key] ??= ['kind' => 'published', 'at' => $first, 'networks' => [], 'state' => 'published'] + $this->card($post, $view);
                $items[$first->format('Y-m-d')][$key]['networks'][] = $target->getNetwork();
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

    // What a post shows on the calendar, wherever it sits, its screen coming back to $view
    /** @return array<string, mixed> */
    private function card(SocialPost $post, string $view): array
    {
        return [
            'id' => $post->getId(),
            'title' => $post->getTitle(),
            'image' => $post->getThumbnailUrl(),
            // The networks it still goes out on
            'networks' => $post->getTargets()->filter(static fn (SocialPostTarget $target): bool => $target->isPending())->map(static fn (SocialPostTarget $target): string => $target->getNetwork())->getValues(),
            'planned_at' => $post->getPlannedAt(),
            'state' => $post->getState(),
            'edit_url' => $this->postUrl($post, Action::EDIT, $view),
        ];
    }

    // A screen of the post: its own, or one of its actions', coming back to the calendar's $view
    private function postUrl(SocialPost $post, string $action, string $view): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setController(SocialPostCrudController::class)
            ->setAction($action)
            ->setEntityId($post->getId())
            ->set(self::FROM, $view)
            ->generateUrl();
    }

    // A date as the visitor's language writes it
    private function format(\DateTimeImmutable $date, string $pattern, string $locale): string
    {
        return (string) new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, $pattern)->format($date);
    }
}
