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
use c975L\SocialBundle\Service\SocialSharedCalendar;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// Another site's feed unread leaves its posts off the calendar, which only says so in small: the dashboard says it too, from the calendar's last read - the dashboard never reads a feed itself
class SocialSharedCalendarAlertProvider implements AlertProviderInterface
{
    public function __construct(
        private readonly SocialSharedCalendar $sharedCalendar,
        private readonly ConfigServiceInterface $configService,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // The sites whose feed failed, as one alert - none when every feed was read, or none is listed
    public function getAlerts(): array
    {
        $errors = $this->sharedCalendar->getKnownErrors();
        if ([] === $errors) {
            return [];
        }

        return [[
            'label' => $this->translator->trans('label.social_calendar_shared_failed', ['%count%' => \count($errors)], 'social'),
            'description' => implode(' - ', array_map(fn (array $error): string => $this->translator->trans('label.social_calendar_shared_' . $error['reason'], ['%site%' => $error['site']], 'social'), $errors)),
            'severity' => Config::SEVERITY_WARNING,
            'url' => $this->urlGenerator->generate(SocialCalendarController::ROUTE),
            'role' => (string) $this->configService->get('site-role-editor'),
        ]];
    }
}
