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
use c975L\SocialBundle\Contract\NetworkPublisherInterface;
use c975L\SocialBundle\Model\MediaRules;
use c975L\SocialBundle\Model\PostMedia;
use c975L\UiBundle\Model\SocialContent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

// Uploads the post's video on the connected YouTube channel - a vertical one of three minutes at most becoming a Short on its own. The text's first line is the title, the rest the description; a post with no video of its own never goes out, the content's picture being no stand-in on a network of videos
class YoutubePublisher implements NetworkPublisherInterface
{
    // What a video's description accepts, in bytes
    private const int MAX_LENGTH = 5000;

    // What a video's title accepts
    private const int MAX_TITLE = 100;

    public function __construct(
        private readonly YoutubeClient $youtubeClient,
        private readonly ConfigServiceInterface $configService,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function getName(): string
    {
        return 'youtube';
    }

    public function isConfigured(): bool
    {
        return $this->youtubeClient->isConnected();
    }

    public function getMaxLength(): int
    {
        return self::MAX_LENGTH;
    }

    // From developers.google.com/youtube/v3/docs/videos/insert: one video per upload, no picture; MP4 and MOV among the accepted containers, 256 GB at most, the duration beyond 15 min reserved to verified channels and not checked here
    public function getMediaRules(): MediaRules
    {
        return new MediaRules(
            maxImages: 0,
            video: true,
            mix: false,
            required: true,
            imageTypes: [],
            videoTypes: ['video/mp4', 'video/quicktime'],
            videoRequired: true,
        );
    }

    // The video's id, its public address being youtu.be/ followed by it
    public function publish(string $text, SocialContent $content, array $medias = []): string
    {
        $video = $this->video($medias);
        $uploaded = $this->youtubeClient->uploadVideo($video->path, $video->mimeType, $this->resource($text, $content));

        // An unaudited API project gets every upload locked private, whatever was asked: said in the log, the video being online all the same
        if ('' !== $uploaded['privacyStatus'] && 'public' !== $uploaded['privacyStatus']) {
            $this->logger->warning('YouTube kept the video {id} {privacy}: the Google Cloud project has to pass YouTube\'s API audit before its uploads can be public.', ['id' => $uploaded['id'], 'privacy' => $uploaded['privacyStatus']]);
        }

        return $uploaded['id'];
    }

    public function preview(string $text, SocialContent $content, array $medias = []): array
    {
        return ['file' => $this->video($medias)->path, 'resource' => $this->resource($text, $content)];
    }

    // The post's one video, throwing rather than falling back on the content's picture
    /** @param list<PostMedia> $medias */
    private function video(array $medias): PostMedia
    {
        foreach ($medias as $media) {
            if ($media->isVideo()) {
                return $media;
            }
        }

        throw new \RuntimeException('YouTube takes a video only: add one to the post.');
    }

    // The "snippet" and "status" parts of the video: its title is the text's first line, the content's title when the text is empty, its description the lines after - the whole text for a single line. The "made for kids" declaration YouTube requires of every upload comes from the site's own setting
    /** @return array<string, mixed> */
    private function resource(string $text, SocialContent $content): array
    {
        [$first, $rest] = explode("\n", trim($text), 2) + [1 => ''];
        $title = '' !== trim($first) ? trim($first) : $content->title;
        $description = '' !== trim($rest) ? trim($rest) : $text;

        return [
            'snippet' => [
                'title' => mb_substr(self::clean($title), 0, self::MAX_TITLE),
                // YouTube counts the description in bytes, cut on a character's boundary
                'description' => mb_strcut(self::clean($description), 0, self::MAX_LENGTH),
            ],
            'status' => [
                'privacyStatus' => 'public',
                'selfDeclaredMadeForKids' => $this->configService->getBool($this->configService->get('social-youtube-made-for-kids')),
            ],
        ];
    }

    // YouTube refuses "<" and ">" in a title or a description, the whole upload failing on them
    private static function clean(string $text): string
    {
        return str_replace(['<', '>'], ['‹', '›'], $text);
    }
}
