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
use c975L\SocialBundle\Model\PostMedia;
use c975L\SocialBundle\Service\YoutubeClient;
use c975L\SocialBundle\Service\YoutubePublisher;
use c975L\UiBundle\Model\SocialContent;
use PHPUnit\Framework\TestCase;

class YoutubePublisherTest extends TestCase
{
    /** @var list<array{path: string, mimeType: string, metadata: array<string, mixed>}> */
    private array $uploads = [];

    private function publisher(string $madeForKids = 'true'): YoutubePublisher
    {
        $client = $this->createStub(YoutubeClient::class);
        $client->method('isConnected')->willReturn(true);
        $client->method('uploadVideo')->willReturnCallback(function (string $path, string $mimeType, array $metadata): array {
            $this->uploads[] = ['path' => $path, 'mimeType' => $mimeType, 'metadata' => $metadata];

            return ['id' => 'vid1', 'privacyStatus' => 'public'];
        });
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $slug): mixed => 'social-youtube-made-for-kids' === $slug ? $madeForKids : null);
        $configService->method('getBool')->willReturnCallback(static fn (mixed $value): bool => filter_var($value, \FILTER_VALIDATE_BOOLEAN));

        return new YoutubePublisher($client, $configService);
    }

    private function content(): SocialContent
    {
        return new SocialContent('1', 'Le Camion et les Souris', 'https://example.org/h4');
    }

    private function video(): PostMedia
    {
        return new PostMedia('/var/medias/reel.mp4', 'https://example.org/medias/reel.mp4', 'video/mp4', width: 1080, height: 1920, duration: 25.0);
    }

    // A network of videos: one, required, never a picture - the content's picture being no stand-in
    public function testItTakesAVideoAndNothingElse(): void
    {
        $rules = $this->publisher()->getMediaRules();

        $this->assertSame([0, true, true, true, ['video/mp4', 'video/quicktime']], [$rules->maxImages, $rules->video, $rules->required, $rules->videoRequired, $rules->videoTypes]);
        $this->assertSame('youtube', $this->publisher()->getName());
        $this->assertSame(5000, $this->publisher()->getMaxLength());
    }

    // The first line is the title, the rest the description, and the "made for kids" declaration comes from the site's setting
    public function testTheFirstLineIsTheTitleAndTheRestTheDescription(): void
    {
        $id = $this->publisher()->publish("Ça mange les souris !\n\nLa suite ce soir.\nhttps://example.org/h4", $this->content(), [$this->video()]);

        $this->assertSame('vid1', $id);
        $this->assertSame('/var/medias/reel.mp4', $this->uploads[0]['path']);
        $this->assertSame([
            'snippet' => ['title' => 'Ça mange les souris !', 'description' => "La suite ce soir.\nhttps://example.org/h4"],
            'status' => ['privacyStatus' => 'public', 'selfDeclaredMadeForKids' => true],
        ], $this->uploads[0]['metadata']);
    }

    // YouTube refuses "<" and ">", and a title past 100 characters
    public function testTheTitleIsCleanedAndCut(): void
    {
        $this->publisher('false')->publish(str_repeat('a', 120) . ' <b>', $this->content(), [$this->video()]);

        $this->assertSame(100, mb_strlen($this->uploads[0]['metadata']['snippet']['title']));
        $this->assertStringNotContainsString('<', $this->uploads[0]['metadata']['snippet']['description']);
        $this->assertFalse($this->uploads[0]['metadata']['status']['selfDeclaredMadeForKids']);
    }

    // A single line is the title and the description alike, rather than leaving the description empty
    public function testASingleLineIsAlsoTheDescription(): void
    {
        $this->publisher()->publish('Ça mange les souris !', $this->content(), [$this->video()]);

        $this->assertSame('Ça mange les souris !', $this->uploads[0]['metadata']['snippet']['description']);
    }

    // The site's setting is followed both ways, the stored "false" included
    public function testMadeForKidsFollowsTheSetting(): void
    {
        $this->publisher('true')->publish('Titre', $this->content(), [$this->video()]);
        $this->publisher('false')->publish('Titre', $this->content(), [$this->video()]);

        $this->assertSame([true, false], array_map(static fn (array $upload): bool => $upload['metadata']['status']['selfDeclaredMadeForKids'], $this->uploads));
    }

    // YouTube limits the description in bytes: an accented text is cut within them, on a character's boundary
    public function testTheDescriptionIsCutInBytes(): void
    {
        $this->publisher()->publish("Titre\n" . str_repeat('é', 4800), $this->content(), [$this->video()]);

        $description = $this->uploads[0]['metadata']['snippet']['description'];
        $this->assertLessThanOrEqual(5000, \strlen($description));
        $this->assertTrue(mb_check_encoding($description, 'UTF-8'));
    }

    // A post with no video of its own never goes out
    public function testAPostWithoutAVideoIsRefused(): void
    {
        $this->expectExceptionMessageIsOrContains('YouTube takes a video only');

        $this->publisher()->publish('Texte', $this->content(), [new PostMedia('/var/medias/p.jpg', 'https://example.org/p.jpg', 'image/jpeg')]);
    }

    // The dry run shows the file and the resource, sending nothing
    public function testThePreviewSendsNothing(): void
    {
        $preview = $this->publisher()->preview('Titre', $this->content(), [$this->video()]);

        $this->assertSame('/var/medias/reel.mp4', $preview['file']);
        $this->assertSame('Titre', $preview['resource']['snippet']['title']);
        $this->assertSame([], $this->uploads);
    }
}
