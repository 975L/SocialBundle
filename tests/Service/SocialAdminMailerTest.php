<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\SiteUrlResolver;
use c975L\SocialBundle\Service\SocialAdminMailer;
use c975L\UiBundle\Model\EmailSendRequest;
use c975L\UiBundle\Service\EmailService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class SocialAdminMailerTest extends TestCase
{
    private ?EmailSendRequest $request = null;

    private function mailer(?string $mailto, ?string $from = null, ?EmailService $emailService = null): SocialAdminMailer
    {
        $config = $this->createStub(ConfigServiceInterface::class);
        $config->method('get')->willReturnMap([['email-to', $mailto], ['email-from', $from]]);
        $siteUrlResolver = $this->createStub(SiteUrlResolver::class);
        $siteUrlResolver->method('siteUrl')->willReturn('https://example.org');
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(static fn (string $route, array $parameters): string => '/management/social-calendar?date=' . $parameters['date']);

        return new SocialAdminMailer($config, $emailService ?? $this->emailService(true), $siteUrlResolver, $urlGenerator);
    }

    private function emailService(bool $sent, ?string $error = null): EmailService
    {
        $emailService = $this->createStub(EmailService::class);
        $emailService->method('send')->willReturnCallback(function (EmailSendRequest $request) use ($sent): bool {
            $this->request = $request;

            return $sent;
        });
        $emailService->method('getLastError')->willReturn($error);

        return $emailService;
    }

    // A back office's address is absolute, the site's url before the route's path, a console having no host of its own
    public function testAnAddressIsAbsolute(): void
    {
        $this->assertSame('https://example.org/management/social-calendar?date=2026-10-09', $this->mailer('admin@example.org')->url('social_calendar', ['date' => '2026-10-09']));
    }

    // A sent email answers null, written in plain text to "email-to" from "email-from"
    public function testASentEmailAnswersNull(): void
    {
        $this->assertNull($this->mailer('admin@example.org', 'site@example.org')->send('Subject', 'Body'));
        $this->assertSame('Subject', $this->request->subject);
        $this->assertSame('Body', $this->request->text);
        $this->assertSame('admin@example.org', $this->request->to);
        $this->assertSame('site@example.org', $this->request->from);
        $this->assertSame('site@example.org', $this->request->replyTo);
    }

    // With no sender configured, the recipient doubles as the sender
    public function testTheRecipientDoublesAsTheSenderWhenNoneIsSet(): void
    {
        $this->mailer('admin@example.org', '')->send('Subject', 'Body');

        $this->assertSame('admin@example.org', $this->request->from);
        $this->assertSame('admin@example.org', $this->request->replyTo);
    }

    // With no recipient configured nothing is sent, the reason answered
    public function testNoRecipientSendsNothing(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('send');

        $this->assertSame('email-to is not configured', $this->mailer(null, emailService: $emailService)->send('Subject', 'Body'));
        $this->assertSame('email-to is not configured', $this->mailer('', emailService: $emailService)->send('Subject', 'Body'));
    }

    // An email refused answers the mailer's own error, or an unknown one when it gave none
    public function testARefusedEmailAnswersItsReason(): void
    {
        $this->assertSame('Connection refused', $this->mailer('admin@example.org', emailService: $this->emailService(false, 'Connection refused'))->send('Subject', 'Body'));
        $this->assertSame('unknown error', $this->mailer('admin@example.org', emailService: $this->emailService(false))->send('Subject', 'Body'));
    }
}
