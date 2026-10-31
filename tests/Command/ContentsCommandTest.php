<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Command;

use c975L\SocialBundle\Command\ContentsCommand;
use c975L\SocialBundle\Service\SocialPublisher;
use c975L\UiBundle\Model\SocialContent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ContentsCommandTest extends TestCase
{
    /** @var list<array{0: list<string>, 1: int}> */
    private array $asked = [];

    private function tester(): CommandTester
    {
        $publisher = $this->createStub(SocialPublisher::class);
        $publisher->method('freeContents')->willReturnCallback(function (array $types, int $limit): array {
            $this->asked[] = [$types, $limit];

            return [['source' => 'book', 'scope' => null, 'content' => new SocialContent('12', 'Le Cerf-volant', 'https://example.org/histoire/cerf-volant', locale: 'fr')]];
        });

        return new CommandTester(new ContentsCommand($publisher));
    }

    // Each free content named as an imported draft's "source" says it, as JSON for a script
    public function testTheFreeContentsAreNamedAsADraftNamesThem(): void
    {
        $tester = $this->tester();
        $tester->execute(['sources' => ['book'], '--limit' => '20', '--json' => true]);

        $this->assertSame([[['book'], 20]], $this->asked);
        $this->assertSame([['source' => 'book:12', 'scope' => null, 'title' => 'Le Cerf-volant', 'url' => 'https://example.org/histoire/cerf-volant', 'locale' => 'fr']], json_decode($tester->getDisplay(), true));
    }

    // Read by a person, they come as a table
    public function testTheyComeAsATableOtherwise(): void
    {
        $tester = $this->tester();
        $tester->execute([]);

        $this->assertStringContainsString('book:12', $tester->getDisplay());
        $this->assertSame([[[], 50]], $this->asked);
    }
}
