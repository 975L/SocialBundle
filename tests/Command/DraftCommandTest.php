<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Command;

use c975L\SocialBundle\Command\DraftCommand;
use c975L\SocialBundle\Service\SocialDraftImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class DraftCommandTest extends TestCase
{
    private bool $imported = false;

    /** @param list<string> $errors */
    private function tester(array $errors = []): CommandTester
    {
        $importer = $this->createStub(SocialDraftImporter::class);
        $importer->method('read')->willReturn(['errors' => $errors, 'drafts' => [] === $errors ? [['at' => new \DateTimeImmutable('2026-11-02 09:30'), 'networks' => ['linkedin'], 'text' => 'One idea', 'comment' => 'Link', 'url' => null, 'media' => '/tmp/card.png']] : []]);
        $importer->method('import')->willReturnCallback(function (): array {
            $this->imported = true;

            return [];
        });

        return new CommandTester(new DraftCommand($importer));
    }

    private function file(): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'draft');
        file_put_contents($file, '{"title": "Bundles", "posts": []}');

        return $file;
    }

    // The dry run lists the drafts and writes nothing
    public function testTheDryRunListsTheDraftsAndWritesNothing(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['file' => $this->file(), '--dry-run' => true]));
        $this->assertStringContainsString('02/11/2026 09:30', $tester->getDisplay());
        $this->assertFalse($this->imported);
    }

    // A file with mistakes writes nothing and fails
    public function testAWrongFileFailsAndWritesNothing(): void
    {
        $tester = $this->tester(['Post 1: "text" is empty.']);

        $this->assertSame(Command::FAILURE, $tester->execute(['file' => $this->file()]));
        $this->assertStringContainsString('"text" is empty', $tester->getDisplay());
        $this->assertFalse($this->imported);
    }

    // A file that is no JSON fails at once
    public function testAnUnreadableFileFails(): void
    {
        $this->assertSame(Command::FAILURE, $this->tester()->execute(['file' => '/nowhere.json']));
    }

    // Without --dry-run the drafts are imported
    public function testTheDraftsAreImported(): void
    {
        $this->tester()->execute(['file' => $this->file()]);

        $this->assertTrue($this->imported);
    }
}
