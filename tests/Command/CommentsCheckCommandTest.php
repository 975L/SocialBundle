<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Command;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Command\CommentsCheckCommand;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Service\SocialAdminMailer;
use c975L\SocialBundle\Service\SocialCommentChecker;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\Translation\TranslatorInterface;

class CommentsCheckCommandTest extends TestCase
{
    /** @var list<string> */
    private array $sent = [];

    private function target(): SocialPostTarget
    {
        return new SocialPostTarget(new SocialPost(SocialPost::SOURCE_MANUAL, 'id', 'Title', 'https://example.org/a', null, new \DateTimeImmutable()), 'bluesky', 'Text');
    }

    /** @param list<array{target: SocialPostTarget, count: int, added: int, url: ?string}> $read */
    private function tester(array $read, int $days = 30, ?string $mailError = null): CommandTester
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn((string) $days);
        $checker = $this->createStub(SocialCommentChecker::class);
        $checker->method('read')->willReturn($read);
        $mailer = $this->createStub(SocialAdminMailer::class);
        $mailer->method('url')->willReturn('https://example.org/calendar');
        $mailer->method('send')->willReturnCallback(function (string $subject, string $body) use ($mailError): ?string {
            $this->sent[] = $body;

            return $mailError;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new CommandTester(new CommentsCheckCommand($checker, $configService, $mailer, $this->createStub(EntityManagerInterface::class), $translator));
    }

    // At 0 nothing is read, the feature staying off until a site asks for it
    public function testNothingIsReadAtZero(): void
    {
        $tester = $this->tester([], 0);
        $tester->execute([]);

        $this->assertStringContainsString('nothing is read', $tester->getDisplay());
    }

    // One email for all of them, each post with its count and address, then remembered
    public function testNewCommentsAreEmailedThenRemembered(): void
    {
        $target = $this->target();
        $this->tester([['target' => $target, 'count' => 3, 'added' => 2, 'url' => 'https://example.org/post']])->execute([]);

        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString("- Title (Bluesky) : +2\n  https://example.org/post", $this->sent[0]);
        $this->assertSame(3, $target->getCommentCount());
    }

    // An email that failed tells them at the next check
    public function testAFailedEmailRemembersNothing(): void
    {
        $target = $this->target();
        $this->tester([['target' => $target, 'count' => 3, 'added' => 2, 'url' => null]], mailError: 'down')->execute([]);

        $this->assertNull($target->getCommentCount());
    }

    // A first reading is remembered without an email
    public function testAFirstReadingSendsNothing(): void
    {
        $target = $this->target();
        $this->tester([['target' => $target, 'count' => 4, 'added' => 0, 'url' => null]])->execute([]);

        $this->assertSame([], $this->sent);
        $this->assertSame(4, $target->getCommentCount());
    }

    // A dry run reading nothing new says so, rather than ending in silence
    public function testADryRunWithNothingNewSaysSo(): void
    {
        $tester = $this->tester([['target' => $this->target(), 'count' => 4, 'added' => 0, 'url' => null]]);
        $tester->execute(['--dry-run' => true]);

        $this->assertStringContainsString('No new comment.', $tester->getDisplay());
    }

    public function testADryRunSendsAndRemembersNothing(): void
    {
        $target = $this->target();
        $this->tester([['target' => $target, 'count' => 3, 'added' => 1, 'url' => null]])->execute(['--dry-run' => true]);

        $this->assertSame([], $this->sent);
        $this->assertNull($target->getCommentCount());
    }
}
