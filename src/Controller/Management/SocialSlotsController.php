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
use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Entity\SocialSlot;
use c975L\SocialBundle\Form\SocialSlotType;
use c975L\SocialBundle\Repository\SocialSeriesRepository;
use c975L\SocialBundle\Repository\SocialSlotRepository;
use c975L\SocialBundle\Service\SocialSlotCalendar;
use c975L\SocialBundle\Service\SocialSlotRelayer;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Translation\TranslatableMessage;

// The template week of the site: its slots laid by day and time, those meeting another one at the same minute flagged, one edited or added beside them; a series' drafts laid again on its slots once one moved
class SocialSlotsController extends AbstractController
{
    // The dashboard prefixes the AdminRoute name with its own route name (see SocialConnectionsController::ROUTE)
    public const string ROUTE = 'management_social_slots';

    private const string DELETE_ROUTE = 'management_social_slots_delete';

    private const string RELAY_ROUTE = 'management_social_slots_relay';

    private const string CSRF_TOKEN = 'social_slots';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly SocialSlotRepository $slotRepository,
        private readonly SocialSeriesRepository $seriesRepository,
        private readonly SocialSlotCalendar $slotCalendar,
        private readonly SocialSlotRelayer $relayer,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    // The week, and the form of the slot asked for - a new one by default - saved then shown on the week
    #[AdminRoute(path: '/social-slots', name: 'social_slots', options: ['methods' => ['GET', 'POST']])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $slot = $request->query->has('slot') ? $this->slotRepository->find($request->query->getInt('slot')) : new SocialSlot();
        if (!$slot instanceof SocialSlot) {
            throw $this->createNotFoundException();
        }
        $form = $this->createForm(SocialSlotType::class, $slot);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($slot);
            $this->entityManager->flush();
            $this->addFlash('success', new TranslatableMessage('flash.social_slot_saved', [], 'social'));

            return $this->redirectToRoute(self::ROUTE);
        }

        $slots = $this->slotRepository->findOrdered();

        return $this->render('@c975LSocial/management/social_slots.html.twig', [
            'form' => $form,
            'slot' => $slot,
            'days' => array_map(static fn (int $day): array => array_values(array_filter($slots, static fn (SocialSlot $item): bool => SocialSlot::MONTH_DAY !== $item->getPace() && $day === $item->getWeekday())), range(1, 7)),
            'month_days' => array_values(array_filter($slots, static fn (SocialSlot $item): bool => SocialSlot::MONTH_DAY === $item->getPace())),
            'clashes' => $this->slotCalendar->clashes($slots, new \DateTimeImmutable('today')),
            'series' => $this->seriesRows($slots),
            'token' => self::CSRF_TOKEN,
            'delete_url' => $this->generateUrl(self::DELETE_ROUTE),
            'relay_url' => $this->generateUrl(self::RELAY_ROUTE),
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    // A slot taken off the week, the series it served then prolonged at the time of its last post again when it has no other
    #[AdminRoute(path: '/social-slots/delete', name: 'social_slots_delete', options: ['methods' => ['POST']])]
    public function delete(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $slot = $this->slotRepository->find($request->request->getInt('id'));
        if ($slot instanceof SocialSlot && $this->isCsrfTokenValid(self::CSRF_TOKEN, $request->request->getString('token'))) {
            $this->entityManager->remove($slot);
            $this->entityManager->flush();
            $this->addFlash('success', new TranslatableMessage('flash.social_slot_deleted', [], 'social'));
        }

        return $this->redirectToRoute(self::ROUTE);
    }

    // A series' drafts to come laid again on its slots, those approved or gone out left where they are
    #[AdminRoute(path: '/social-slots/relay', name: 'social_slots_relay', options: ['methods' => ['POST']])]
    public function relay(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $series = $this->seriesRepository->find($request->request->getInt('series'));
        if ($series instanceof SocialSeries && $this->isCsrfTokenValid(self::CSRF_TOKEN, $request->request->getString('token'))) {
            $this->addFlash('success', new TranslatableMessage('flash.social_slot_relayed', ['%count%' => $this->relayer->relay($series)], 'social'));
        }

        return $this->redirectToRoute(self::ROUTE);
    }

    // Each series prolonged on the slots, with how many of its drafts to come stand off them
    /**
     * @param list<SocialSlot> $slots
     *
     * @return list<array{series: SocialSeries, off: int}>
     */
    private function seriesRows(array $slots): array
    {
        $rows = [];
        foreach ($slots as $slot) {
            $series = $slot->getSeries();
            if (null !== $series && !isset($rows[(int) $series->getId()])) {
                $rows[(int) $series->getId()] = ['series' => $series, 'off' => $this->relayer->countOffSlot($series)];
            }
        }

        return array_values($rows);
    }
}
