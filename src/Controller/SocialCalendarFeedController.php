<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Controller;

use c975L\SocialBundle\Controller\Management\SocialCalendarController;
use c975L\SocialBundle\Service\SocialCalendarFeed;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// The publications calendar as an iCalendar feed, outside the back office: a calendar client subscribes with no session, its secret address being its only key (see SocialCalendarFeed)
class SocialCalendarFeedController extends AbstractController
{
    public const string ROUTE = 'social_calendar_feed';

    public function __construct(
        private readonly SocialCalendarFeed $feed,
    ) {
    }

    // A wrong or withdrawn address answers as a page that does not exist, telling nothing of the feed
    #[Route('/social/calendar/{token}.ics', name: self::ROUTE, requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function feed(Request $request, string $token): Response
    {
        if (!$this->feed->isToken($token)) {
            throw new NotFoundHttpException();
        }

        $ics = $this->feed->build($request->getHost(), $this->generateUrl(SocialCalendarController::ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL));

        return new Response($ics, Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="social-calendar.ics"',
            // Never kept by a shared cache: the address is the key
            'Cache-Control' => 'private, max-age=900',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
