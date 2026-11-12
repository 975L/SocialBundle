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
use c975L\SocialBundle\Model\PostComment;
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

    /** @param list<array{target: SocialPostTarget, count: int, added: int, url: ?string, comments: ?list<PostComment>}> $read */
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
        $this->tester([['target' => $target, 'count' => 3, 'added' => 2, 'url' => 'https://example.org/post', 'comments' => []]])->execute([]);

        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString("- Title (Bluesky) : +2\n  https://example.org/post", $this->sent[0]);
        $this->assertSame(3, $target->getCommentCount());
    }

    // An email that failed tells them at the next check
    public function testAFailedEmailRemembersNothing(): void
    {
        $target = $this->target();
        $this->tester([['target' => $target, 'count' => 3, 'added' => 2, 'url' => null, 'comments' => []]], mailError: 'down')->execute([]);

        $this->assertNull($target->getCommentCount());
    }

    // A first reading is remembered without an email
    public function testAFirstReadingSendsNothing(): void
    {
        $target = $this->target();
        $this->tester([['target' => $target, 'count' => 4, 'added' => 0, 'url' => null, 'comments' => []]])->execute([]);

        $this->assertSame([], $this->sent);
        $this->assertSame(4, $target->getCommentCount());
    }

    // A dry run reading nothing new says so, rather than ending in silence
    public function testADryRunWithNothingNewSaysSo(): void
    {
        $tester = $this->tester([['target' => $this->target(), 'count' => 4, 'added' => 0, 'url' => null, 'comments' => []]]);
        $tester->execute(['--dry-run' => true]);

        $this->assertStringContainsString('No new comment.', $tester->getDisplay());
    }

    public function testADryRunSendsAndRemembersNothing(): void
    {
        $target = $this->target();
        $this->tester([['target' => $target, 'count' => 3, 'added' => 1, 'url' => null, 'comments' => []]])->execute(['--dry-run' => true]);

        $this->assertSame([], $this->sent);
        $this->assertNull($target->getCommentCount());
    }

    // Each new comment on one line under its post, its spaces folded, cut when long, its author after it
    public function testTheCommentsAreQuotedUnderTheirPost(): void
    {
        $long = new PostComment('', str_repeat('a', 300));
        $this->tester([['target' => $this->target(), 'count' => 2, 'added' => 2, 'url' => 'https://example.org/post', 'comments' => [new PostComment('Ann', "Nice\n\n  story"), $long]]])->execute([]);

        $this->assertStringContainsString("- Title (Bluesky) : +2\n  email.social_comments_quote - Ann\n  email.social_comments_quote\n  https://example.org/post", $this->sent[0]);
    }

    // The text the translator receives, folded and cut
    public function testAQuoteIsFoldedAndCut(): void
    {
        $quotes = [];
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $parameters) use (&$quotes): string {
            if (isset($parameters['%text%'])) {
                $quotes[] = $parameters['%text%'];
            }

            return $id;
        });
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('30');
        $checker = $this->createStub(SocialCommentChecker::class);
        $checker->method('read')->willReturn([['target' => $this->target(), 'count' => 2, 'added' => 2, 'url' => null, 'comments' => [new PostComment('Ann', "Nice\n\n  story"), new PostComment('', str_repeat('a', 300))]]]);
        new CommandTester(new CommentsCheckCommand($checker, $configService, $this->createStub(SocialAdminMailer::class), $this->createStub(EntityManagerInterface::class), $translator))->execute([]);

        $this->assertSame('Nice story', $quotes[0]);
        $this->assertSame(280, mb_strlen($quotes[1]));
        $this->assertStringEndsWith('…', $quotes[1]);
    }

    // A text refused says where to connect the network again, the post's address kept
    public function testARefusedTextSaysToConnectAgain(): void
    {
        $this->tester([['target' => $this->target(), 'count' => 2, 'added' => 1, 'url' => 'https://example.org/post', 'comments' => null]])->execute([]);

        $this->assertStringContainsString("+1\n  email.social_comments_unread\n  https://example.org/calendar\n  https://example.org/post", $this->sent[0]);
    }

    // A comment's markup is shown, not read as the console's tags
    public function testAQuoteIsEscapedOnTheConsole(): void
    {
        $tester = $this->tester([['target' => $this->target(), 'count' => 1, 'added' => 1, 'url' => null, 'comments' => [new PostComment('<error>x</error>', 'Hi')]]]);
        $tester->execute(['--dry-run' => true]);

        $this->assertStringContainsString('<error>x</error>', $tester->getDisplay());
    }
}
