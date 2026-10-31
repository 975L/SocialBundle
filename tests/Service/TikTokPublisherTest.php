<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Entity\SocialMedia;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Model\PostMedia;
use c975L\SocialBundle\Service\TikTokClient;
use c975L\SocialBundle\Service\TikTokPublisher;
use c975L\UiBundle\Model\SocialContent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Translation\TranslatableMessage;

class TikTokPublisherTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $posted = [];

    private const array CHOICES = ['privacy' => 'SELF_ONLY', 'consent' => true];

    private const array CREATOR = ['nickname' => 'Mystic', 'privacy_options' => ['FOLLOWER_OF_CREATOR', 'SELF_ONLY'], 'comment_disabled' => false, 'duet_disabled' => true, 'stitch_disabled' => true, 'max_duration' => 180];

    // TikTok answering the statuses given in turn, no wait between them
    /** @param list<array{status: string, reason: ?string}> $statuses */
    private function publisher(array $statuses = [['status' => 'PUBLISH_COMPLETE', 'reason' => null]], ?\RuntimeException $refusal = null, bool $connected = true): TikTokPublisher
    {
        $client = $this->createStub(TikTokClient::class);
        $client->method('isConnected')->willReturn($connected);
        if (null === $refusal) {
            $client->method('creatorInfo')->willReturn(self::CREATOR);
        } else {
            $client->method('creatorInfo')->willThrowException($refusal);
        }
        $client->method('postVideo')->willReturnCallback(function (array $postInfo, string $videoUrl): string {
            $this->posted[] = ['post_info' => $postInfo, 'video_url' => $videoUrl];

            return 'v_pub_1';
        });
        $client->method('status')->willReturnCallback(static function () use (&$statuses): array {
            return array_shift($statuses) ?? ['status' => 'PROCESSING_DOWNLOAD', 'reason' => null];
        });

        return new TikTokPublisher($client, new NullLogger(), 0);
    }

    private function content(): SocialContent
    {
        return new SocialContent('1', 'Nordkapp', 'https://example.org/films/nordkapp');
    }

    /** @return list<PostMedia> */
    private function video(): array
    {
        return [new PostMedia('/tmp/nordkapp.mp4', 'https://example.org/medias/nordkapp.mp4', 'video/mp4', duration: 42.0)];
    }

    /**
     * @param list<TranslatableMessage> $problems
     *
     * @return list<string>
     */
    private function keys(array $problems): array
    {
        return array_map(static fn (TranslatableMessage $message): string => $message->getMessage(), $problems);
    }

    private function post(?float $duration): SocialPost
    {
        $post = new SocialPost(SocialPost::SOURCE_MANUAL, 'abc', 'Nordkapp', '', null, new \DateTimeImmutable('+1 day'));
        $media = SocialMedia::reference($post, 'medias/nordkapp.mp4', 'video/mp4');
        $media->setDimensions(null, null, $duration);
        $post->addMedia($media);

        return $post;
    }

    // A network of videos alone, the account's own length checked with its choices
    public function testOnlyAVideoGoesOut(): void
    {
        $rules = $this->publisher()->getMediaRules();

        $this->assertTrue($rules->videoRequired);
        $this->assertSame(0, $rules->maxImages);
        $this->assertSame(['video/mp4', 'video/quicktime', 'video/webm'], $rules->videoTypes);
    }

    // The caption and the choices made, the interactions off unless ticked or turned off by the account since, the video fetched by TikTok from its address
    public function testThePostCarriesTheChoicesMade(): void
    {
        $this->assertSame('v_pub_1', $this->publisher()->withOptions(self::CHOICES + ['comment' => true, 'duet' => true])->publish('Le Cap Nord #nordkapp', $this->content(), $this->video()));

        $this->assertSame([
            'post_info' => [
                'title' => 'Le Cap Nord #nordkapp',
                'privacy_level' => 'SELF_ONLY',
                'disable_comment' => false,
                'disable_duet' => true,
                'disable_stitch' => true,
                'brand_organic_toggle' => false,
                'brand_content_toggle' => false,
            ],
            'video_url' => 'https://example.org/medias/nordkapp.mp4',
        ], $this->posted[0]);
    }

    // Nothing goes out that was not agreed to: no privacy picked, no consent or a commercial content left undisclosed throws before anything is sent, "Publish" passing no check
    public function testNoChoiceNothingSent(): void
    {
        foreach ([[], self::CHOICES + ['commercial' => true]] as $options) {
            try {
                $this->publisher()->withOptions($options)->publish('Le Cap Nord', $this->content(), $this->video());
                $this->fail('A post without its choices went out.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('choices of the post\'s screen', $exception->getMessage());
            }
        }

        $this->assertSame([], $this->posted);
    }

    // A privacy the account stopped offering since the approval throws, asked live just before posting
    public function testAPrivacyNoLongerOfferedIsNotSent(): void
    {
        $this->expectExceptionMessage('no longer offers this privacy');

        try {
            $this->publisher()->withOptions(['privacy' => 'PUBLIC_TO_EVERYONE', 'consent' => true])->publish('Le Cap Nord', $this->content(), $this->video());
        } finally {
            $this->assertSame([], $this->posted);
        }
    }

    // The processing followed until it ends, a failure thrown with TikTok's reason so the target says it
    public function testAFailedProcessingSaysTikToksReason(): void
    {
        $this->expectExceptionMessage('TikTok could not post the video: duration_check_failed');

        $this->publisher([['status' => 'PROCESSING_DOWNLOAD', 'reason' => null], ['status' => 'FAILED', 'reason' => 'duration_check_failed']])->withOptions(self::CHOICES)->publish('Le Cap Nord', $this->content(), $this->video());
    }

    // Only a complete publication ends the wait, a status TikTok left empty being followed further
    public function testOnlyACompletePublicationEndsTheWait(): void
    {
        $this->expectExceptionMessage('TikTok could not post the video: spam_risk');

        $this->publisher([['status' => '', 'reason' => null], ['status' => 'FAILED', 'reason' => 'spam_risk']])->withOptions(self::CHOICES)->publish('Le Cap Nord', $this->content(), $this->video());
    }

    // A processing still going on is left to TikTok, the post's id kept
    public function testALongProcessingIsLeftToTikTok(): void
    {
        $this->assertSame('v_pub_1', $this->publisher([])->withOptions(self::CHOICES)->publish('Le Cap Nord', $this->content(), $this->video()));
    }

    // A post with no video throws rather than falling back on the content's picture
    public function testAPostWithoutVideoThrows(): void
    {
        $this->expectExceptionMessage('TikTok takes a video only');

        $this->publisher()->withOptions(self::CHOICES)->publish('Le Cap Nord', $this->content());
    }

    // The caption cut to what TikTok counts, an emoji counting two
    public function testTheCaptionIsCutInUtf16Units(): void
    {
        $preview = $this->publisher()->withOptions(self::CHOICES)->preview(str_repeat('🎬', 1200), $this->content(), $this->video());

        $this->assertSame(1100, mb_strlen($preview['post_info']['title']));
    }

    // The choices TikTok requires said one by one, nothing asked of TikTok when the account is not connected
    public function testMissingChoicesAreSaid(): void
    {
        $problems = $this->publisher(connected: false)->checkOptions(['commercial' => true, 'brand_content' => true, 'privacy' => 'SELF_ONLY'], $this->post(42.0));
        $this->assertSame(['label.social_tiktok_branded_private', 'label.social_tiktok_consent_required'], $this->keys($problems));

        $this->assertSame(['label.social_tiktok_privacy_required', 'label.social_tiktok_commercial_required', 'label.social_tiktok_consent_required'], $this->keys($this->publisher(connected: false)->checkOptions(['commercial' => true], $this->post(42.0))));

        // A branded content left ticked under an unticked disclosure is not sent, so it keeps no private video from going out
        $this->assertSame([], $this->publisher(connected: false)->checkOptions(['brand_content' => true, 'privacy' => 'SELF_ONLY', 'consent' => true], $this->post(42.0)));
    }

    // What the account allows checked live at the approval: a privacy it no longer offers, a video longer than it takes, an account that may not post
    public function testTheAccountsLimitsAreChecked(): void
    {
        $this->assertSame([], $this->publisher()->checkOptions(self::CHOICES, $this->post(42.0), true));
        $this->assertSame(['label.social_tiktok_privacy_unavailable', 'label.social_tiktok_video_too_long'], $this->keys($this->publisher()->checkOptions(['privacy' => 'PUBLIC_TO_EVERYONE', 'consent' => true], $this->post(240.0), true)));
        $this->assertSame(['label.social_tiktok_cannot_post'], $this->keys($this->publisher(refusal: new \RuntimeException('spam_risk_too_many_posts'))->checkOptions(self::CHOICES, $this->post(42.0), true)));
    }

    // A mere save never waits on TikTok, the account asked at the approval only
    public function testASaveDoesNotAskTikTok(): void
    {
        $this->assertSame([], $this->publisher(refusal: new \RuntimeException('spam_risk_too_many_posts'))->checkOptions(['privacy' => 'PUBLIC_TO_EVERYONE', 'consent' => true], $this->post(240.0)));
    }
}
