<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Controller\Management\SocialPinterestBoardController;
use c975L\SocialBundle\Service\ConfigValueWriter;
use c975L\SocialBundle\Service\PinterestClient;
use c975L\SocialBundle\Tests\Controller\ControllerTestTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class SocialPinterestBoardControllerTest extends TestCase
{
    use ControllerTestTrait;

    /** @var list<array<string, string|null>> */
    private array $written = [];

    // The account's boards as Pinterest answers them, connected or not
    /** @param list<array{id: string, name: string}> $boards */
    private function index(Request $request, array $boards, bool $connected = true): string
    {
        $request->setSession(new Session(new MockArraySessionStorage()));

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key) => 'site-role-editor' === $key ? 'ROLE_EDITOR' : null);
        $pinterestClient = $this->createStub(PinterestClient::class);
        $pinterestClient->method('isConnected')->willReturn($connected);
        $pinterestClient->method('boards')->willReturn($boards);
        $configValueWriter = $this->createStub(ConfigValueWriter::class);
        $configValueWriter->method('write')->willReturnCallback(function (array $values): void {
            $this->written[] = $values;
        });

        $controller = new SocialPinterestBoardController($configService, $configValueWriter, $pinterestClient);
        $controller->setContainer($this->createContainer($request));

        return (string) $controller->index($request)->headers->get('Location');
    }

    // A single board is taken at once, its name kept
    public function testASingleBoardIsTakenAtOnce(): void
    {
        $request = new Request();

        $this->assertSame('/management_social_connections', $this->index($request, [['id' => '1', 'name' => 'Histoires']]));
        $this->assertSame([['social-pinterest-board-id' => '1', 'social-pinterest-board-name' => 'Histoires']], $this->written);
        $this->assertSame(['success' => ['flash.pinterest_board_picked']], $this->flashes($request));
    }

    // No public board sends the owner to create one on Pinterest, nothing written
    public function testNoBoardSaysToCreateOne(): void
    {
        $request = new Request();

        $this->index($request, []);

        $this->assertSame([], $this->written);
        $this->assertSame(['warning' => ['flash.pinterest_no_board']], $this->flashes($request));
    }

    // The board picked among several, the CSRF token checked
    public function testTheBoardPickedIsStored(): void
    {
        $request = new Request(request: ['token' => 'valid', 'board' => '2']);
        $request->setMethod('POST');

        $this->index($request, [['id' => '1', 'name' => 'Histoires'], ['id' => '2', 'name' => 'Cartes']]);

        $this->assertSame([['social-pinterest-board-id' => '2', 'social-pinterest-board-name' => 'Cartes']], $this->written);
    }

    // Not connected, nothing to pick from
    public function testNotConnectedGoesBackToTheConnections(): void
    {
        $this->assertSame('/management_social_connections', $this->index(new Request(), [['id' => '1', 'name' => 'Histoires']], false));
        $this->assertSame([], $this->written);
    }
}
