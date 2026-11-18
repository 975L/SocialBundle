<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Command;

use c975L\SocialBundle\Command\DraftsReminderCommand;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Service\SocialAdminMailer;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\Translation\TranslatorInterface;

class DraftsReminderCommandTest extends TestCase
{
    /** @var list<string> */
    private array $sent = [];

    /** @var list<\DateTimeImmutable> */
    private array $window = [];

    /** @param list<SocialPost> $drafts */
    private function tester(array $drafts, ?string $error = null): CommandTester
    {
        $repository = $this->createStub(SocialPostRepository::class);
        $repository->method('findDraftsPlannedBetween')->willReturnCallback(function (\DateTimeImmutable $from, \DateTimeImmutable $to) use ($drafts): array {
            $this->window = [$from, $to];

            return $drafts;
        });
        $mailer = $this->createStub(SocialAdminMailer::class);
        $mailer->method('send')->willReturnCallback(function (string $subject, string $body) use ($error): ?string {
            $this->sent[] = $body;

            return $error;
        });
        $mailer->method('url')->willReturnCallback(static fn (string $route, array $parameters): string => 'https://example.org/' . $route . '?' . http_build_query($parameters));
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new CommandTester(new DraftsReminderCommand($repository, $mailer, $translator));
    }

    private function draft(): SocialPost
    {
        $post = $this->createStub(SocialPost::class);
        $post->method('getId')->willReturn(5);
        $post->method('getTitle')->willReturn('item-1');
        $post->method('getPlannedAt')->willReturn(new \DateTimeImmutable('2026-11-06 20:30'));
        $post->method('getTargets')->willReturn(new ArrayCollection([$this->target('bluesky', true), $this->target('instagram', false), $this->target('facebook', true)]));

        return $post;
    }

    private function target(string $network, bool $draft): SocialPostTarget
    {
        $target = new SocialPostTarget(new SocialPost('book', '1', 'item-1', 'https://example.org/item-1', null, new \DateTimeImmutable('2026-11-06 20:30')), $network, 'item-1');
        if (!$draft) {
            $target->approve();
        }

        return $target;
    }

    // A post due in draft is told with its moment, its networks still in draft and a link to its panel on the calendar's day
    public function testADraftDueIsToldWithItsPanel(): void
    {
        $this->assertSame(Command::SUCCESS, $this->tester([$this->draft()])->execute([]));

        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('06/11/2026 20:30 : item-1 (Bluesky, Facebook)', $this->sent[0]);
        $this->assertStringContainsString('date=2026-11-06&post=5', $this->sent[0]);
    }

    // The window runs from now to the end of the day after tomorrow, a draft made late told as well
    public function testTheWindowEndsWithTheDayAfterTomorrow(): void
    {
        $before = new \DateTimeImmutable();
        $this->tester([])->execute([]);

        $this->assertGreaterThanOrEqual($before, $this->window[0]);
        $this->assertEquals(new \DateTimeImmutable('today')->modify('+' . (DraftsReminderCommand::DAYS_AHEAD + 1) . ' days'), $this->window[1]);
    }

    // The window ends at midnight, even on the day the clocks go back
    public function testTheWindowEndsAtMidnightAcrossAClockChange(): void
    {
        $end = new \DateTimeImmutable('2026-10-23', new \DateTimeZone('Europe/Paris'))->modify('+' . (DraftsReminderCommand::DAYS_AHEAD + 1) . ' days');

        $this->assertSame('2026-10-26 00:00', $end->format('Y-m-d H:i'));
    }

    // No draft due, no email
    public function testNothingIsSentWithoutADraftDue(): void
    {
        $this->assertSame(Command::SUCCESS, $this->tester([])->execute([]));

        $this->assertSame([], $this->sent);
    }

    // A dry run lists the posts and sends nothing
    public function testADryRunSendsNothing(): void
    {
        $tester = $this->tester([$this->draft()]);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        $this->assertStringContainsString('item-1', $tester->getDisplay());
        $this->assertSame([], $this->sent);
    }

    // A reminder not sent is a warning, not a failure raising an alert
    public function testAReminderNotSentIsAWarning(): void
    {
        $tester = $this->tester([$this->draft()], 'No recipient');

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('No recipient', $tester->getDisplay());
    }
}
