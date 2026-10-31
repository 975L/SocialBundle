<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\SocialBundle\Contract\NetworkPublisherInterface;
use c975L\SocialBundle\Contract\PostOptionsPublisherInterface;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Form\TikTokOptionsType;
use c975L\SocialBundle\Model\MediaRules;
use c975L\SocialBundle\Model\PostMedia;
use c975L\UiBundle\Model\SocialContent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Translation\TranslatableMessage;

use function Symfony\Component\Translation\t;

// Posts the post's video on the connected TikTok account, the text as its caption. TikTok fetches the video itself from the site, whose domain has to be verified in the app; it then processes it for a few minutes, followed here up to five minutes before handing back. TikTok asks the choices of its own screen post by post - privacy, interactions, commercial disclosure, consent - made on the post's screen (see TikTokOptionsType) and checked before the post is approved. Until TikTok audits the app, every post stays private, on an account set private
class TikTokPublisher implements NetworkPublisherInterface, PostOptionsPublisherInterface
{
    // What a caption accepts, in UTF-16 units
    private const int MAX_LENGTH = 2200;

    // How many times the processing is asked about before handing back, about five minutes as Instagram's video, TikTok carrying on alone after that (a sleep counting for nothing in PHP's time limit)
    private const int POLLS = 100;

    // The privacy a branded content cannot take
    public const string PRIVATE = 'SELF_ONLY';

    /** @param array<string, mixed> $options */
    public function __construct(
        private readonly TikTokClient $tikTokClient,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $pollSeconds = 3,
        private readonly array $options = [],
    ) {
    }

    // The network's name, as the targets carry it
    public function getName(): string
    {
        return 'tiktok';
    }

    // Connected, the access token renewing itself from the refresh token
    public function isConfigured(): bool
    {
        return $this->tikTokClient->isConnected();
    }

    // What a caption accepts
    public function getMaxLength(): int
    {
        return self::MAX_LENGTH;
    }

    // From developers.tiktok.com/doc/content-posting-api-media-transfer-guide: one video, no picture; MP4, MOV or WebM, 4 GB at most, 3 seconds to 10 minutes - the account's own limit, often 3 minutes, checked with its choices
    public function getMediaRules(): MediaRules
    {
        return new MediaRules(
            maxImages: 0,
            video: true,
            mix: false,
            required: true,
            imageTypes: [],
            videoTypes: ['video/mp4', 'video/quicktime', 'video/webm'],
            maxVideoBytes: 4000000000,
            minDuration: 3,
            maxDuration: 600,
            videoRequired: true,
        );
    }

    // The form of TikTok's choices on the post's screen
    public function getOptionsType(): string
    {
        return TikTokOptionsType::class;
    }

    // The choices TikTok requires, then, $live at the approval, what the account allows right now - a post TikTok would refuse never being approved
    public function checkOptions(array $options, SocialPost $post, bool $live = false): array
    {
        $problems = $this->choiceProblems($options);

        return $live && $this->tikTokClient->isConnected() ? [...$problems, ...$this->accountProblems((string) ($options['privacy'] ?? ''), $post)] : $problems;
    }

    // The same publisher, carrying the choices made on one post
    public function withOptions(array $options): NetworkPublisherInterface
    {
        return new self($this->tikTokClient, $this->logger, $this->pollSeconds, $options);
    }

    // The id TikTok follows the post with, its processing waited for until complete: a failure throws with TikTok's reason, a processing still going on is left to TikTok
    public function publish(string $text, SocialContent $content, array $medias = []): string
    {
        $video = $this->video($medias);
        // Asked again just before posting, as TikTok requires: an account that may not post right now throws here, one changed since the approval is followed
        $publishId = $this->tikTokClient->postVideo($this->postInfo($text, $this->tikTokClient->creatorInfo(true)), $video->url);

        for ($poll = 0; $poll < self::POLLS; ++$poll) {
            if ($this->pollSeconds > 0) {
                sleep($this->pollSeconds);
            }
            $status = $this->tikTokClient->status($publishId);
            if ('FAILED' === $status['status']) {
                throw new \RuntimeException('TikTok could not post the video: ' . ($status['reason'] ?? 'no reason given'));
            }
            if ('PUBLISH_COMPLETE' === $status['status']) {
                return $publishId;
            }
        }

        $this->logger->info('TikTok is still processing the post {id}: it shows on the account once done.', ['id' => $publishId]);

        return $publishId;
    }

    // What would be sent, nothing sent nor asked of TikTok
    public function preview(string $text, SocialContent $content, array $medias = []): array
    {
        return ['video_url' => $this->video($medias)->url, 'post_info' => $this->postInfo($text)];
    }

    // What the post's screen left undone: no privacy, a disclosure saying nothing, a branded content kept private, no consent
    /**
     * @param array<string, mixed> $options
     *
     * @return list<TranslatableMessage>
     */
    private function choiceProblems(array $options): array
    {
        $privacy = (string) ($options['privacy'] ?? '');
        $commercial = true === ($options['commercial'] ?? false);
        // A branded content left ticked under an unticked disclosure is not sent (see postInfo()), so it says nothing
        $brandContent = $commercial && true === ($options['brand_content'] ?? false);

        return array_values(array_filter([
            '' === $privacy ? t('label.social_tiktok_privacy_required', [], 'social') : null,
            $commercial && true !== ($options['brand_organic'] ?? false) && !$brandContent ? t('label.social_tiktok_commercial_required', [], 'social') : null,
            $brandContent && self::PRIVATE === $privacy ? t('label.social_tiktok_branded_private', [], 'social') : null,
            true !== ($options['consent'] ?? false) ? t('label.social_tiktok_consent_required', [], 'social') : null,
        ]));
    }

    // What the account no longer allows: a privacy it stopped offering, a video longer than it takes, or posting at all right now
    /** @return list<TranslatableMessage> */
    private function accountProblems(string $privacy, SocialPost $post): array
    {
        try {
            $creator = $this->tikTokClient->creatorInfo();
        } catch (\RuntimeException $exception) {
            return [t('label.social_tiktok_cannot_post', ['%error%' => $exception->getMessage()], 'social')];
        }

        $problems = [];
        if ('' !== $privacy && !\in_array($privacy, $creator['privacy_options'], true)) {
            $problems[] = t('label.social_tiktok_privacy_unavailable', [], 'social');
        }
        foreach ($post->getMedias() as $media) {
            if ($media->isVideo() && null !== $creator['max_duration'] && $media->getDuration() > $creator['max_duration']) {
                $problems[] = t('label.social_tiktok_video_too_long', ['%max%' => $creator['max_duration']], 'social');
            }
        }

        return $problems;
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

        throw new \RuntimeException('TikTok takes a video only: add one to the post.');
    }

    // The "post_info" of the post: the caption, and the choices made on the post's screen - the interactions off unless ticked, as TikTok requires them by default, and off whatever was ticked when $creator, the account asked live, turned them off. A choice left undone, or a privacy the account no longer offers, throws: nothing goes out that was not agreed to
    /**
     * @param array{privacy_options: list<string>, comment_disabled: bool, duet_disabled: bool, stitch_disabled: bool}|null $creator
     *
     * @return array<string, mixed>
     */
    private function postInfo(string $text, ?array $creator = null): array
    {
        if ([] !== $this->choiceProblems($this->options)) {
            throw new \RuntimeException('TikTok asks for the choices of the post\'s screen to be complete before posting.');
        }
        $privacy = (string) $this->options['privacy'];
        if (null !== $creator && !\in_array($privacy, $creator['privacy_options'], true)) {
            throw new \RuntimeException('TikTok no longer offers this privacy on the account: pick another one on the post\'s screen.');
        }

        return [
            'title' => self::cut(trim($text)),
            'privacy_level' => $privacy,
            'disable_comment' => ($creator['comment_disabled'] ?? false) || true !== ($this->options['comment'] ?? false),
            'disable_duet' => ($creator['duet_disabled'] ?? false) || true !== ($this->options['duet'] ?? false),
            'disable_stitch' => ($creator['stitch_disabled'] ?? false) || true !== ($this->options['stitch'] ?? false),
            'brand_organic_toggle' => true === ($this->options['commercial'] ?? false) && true === ($this->options['brand_organic'] ?? false),
            'brand_content_toggle' => true === ($this->options['commercial'] ?? false) && true === ($this->options['brand_content'] ?? false),
        ];
    }

    // The caption cut to what TikTok counts, UTF-16 units, an emoji counting two
    private static function cut(string $text): string
    {
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        if (\strlen($utf16) <= self::MAX_LENGTH * 2) {
            return $text;
        }

        return mb_convert_encoding(mb_strcut($utf16, 0, self::MAX_LENGTH * 2, 'UTF-16LE'), 'UTF-8', 'UTF-16LE');
    }
}
