<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Management;

use c975L\ConfigBundle\Entity\Config;
use c975L\ConfigBundle\Management\AlertProviderInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Controller\Management\SocialCalendarController;
use c975L\SocialBundle\Repository\SocialPostRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// A draft never goes out, and nothing says so when its moment passes: the posts planned within a day still waiting on a network's approval are said on the dashboard, the calendar opening on the first of them
class SocialDraftAlertProvider implements AlertProviderInterface
{
    // How far ahead a draft is worth saying
    private const string NOTICE = '+24 hours';

    public function __construct(
        private readonly SocialPostRepository $postRepository,
        private readonly ConfigServiceInterface $configService,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // The posts due within a day with a network still in draft, as one alert on the dashboard - none when nothing is left to approve
    public function getAlerts(): array
    {
        $now = new \DateTimeImmutable();
        $drafts = $this->postRepository->findDraftsPlannedBetween($now, $now->modify(self::NOTICE));
        if ([] === $drafts) {
            return [];
        }

        $first = $drafts[0];

        return [[
            'label' => $this->translator->trans('label.social_drafts_due', ['%count%' => \count($drafts)], 'social'),
            'description' => $this->translator->trans('description.social_drafts_due', ['%time%' => $first->getPlannedAt()->format('d/m H:i'), '%title%' => $first->getTitle()], 'social'),
            'severity' => Config::SEVERITY_WARNING,
            // The first one's day, its panel open
            'url' => $this->urlGenerator->generate(SocialCalendarController::ROUTE, ['view' => SocialCalendarController::DAY, 'date' => $first->getPlannedAt()->format('Y-m-d'), 'post' => $first->getId()]),
            'role' => (string) $this->configService->get('site-role-editor'),
        ]];
    }
}
