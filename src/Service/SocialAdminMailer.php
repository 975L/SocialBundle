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
use c975L\ConfigBundle\Service\SiteUrlResolver;
use c975L\UiBundle\Model\EmailSendRequest;
use c975L\UiBundle\Service\EmailService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// The emails the publication sends the site's administrator ("email-to"): a series coming to its end, a post refused by a network - plain text, put in the site's layout by EmailService
class SocialAdminMailer
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly EmailService $emailService,
        private readonly SiteUrlResolver $siteUrlResolver,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    // An address of the back office, absolute - a console having no request to take the host from
    /** @param array<string, mixed> $parameters */
    public function url(string $route, array $parameters = []): string
    {
        return (string) $this->siteUrlResolver->siteUrl() . $this->urlGenerator->generate($route, $parameters);
    }

    // Null once sent, the reason it was not otherwise
    public function send(string $subject, string $body): ?string
    {
        $mailto = (string) $this->configService->get('email-to');
        if ('' === $mailto) {
            return 'email-to is not configured';
        }

        // An empty sender leaves EmailService with no From to resolve - the recipient doubles as the sender then, an address that is by definition deliverable here
        $from = (string) $this->configService->get('email-from');
        $sender = '' !== $from ? $from : $mailto;

        $sent = $this->emailService->send(new EmailSendRequest(
            subject: $subject,
            context: [],
            from: $sender,
            to: $mailto,
            replyTo: $sender,
            text: $body,
        ));

        return $sent ? null : ($this->emailService->getLastError() ?? 'unknown error');
    }
}
