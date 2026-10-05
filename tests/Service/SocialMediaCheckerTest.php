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
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Model\MediaRules;
use c975L\SocialBundle\Service\SocialMediaChecker;
use c975L\SocialBundle\Service\SocialPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\TranslatableMessage;

class SocialMediaCheckerTest extends TestCase
{
    // Bluesky's and Instagram's own rules, as their publishers declare them
    private function checker(): SocialMediaChecker
    {
        $publisher = $this->createStub(SocialPublisher::class);
        $publisher->method('getMediaRules')->willReturnCallback(static fn (string $network): ?MediaRules => match ($network) {
            'bluesky' => new MediaRules(maxImages: 4, video: true, maxImageBytes: 2000000, maxVideoBytes: 300000000, maxDuration: 600.0),
            'instagram' => new MediaRules(maxImages: 10, video: true, mix: true, required: true, videoTypes: ['video/mp4', 'video/quicktime'], minDuration: 3.0),
            default => null,
        });

        return new SocialMediaChecker($publisher);
    }

    // A post written on its screen, going to the networks given, with the medias given
    private function post(array $networks, array $medias): SocialPost
    {
        $post = new SocialPost(SocialPost::SOURCE_MANUAL, 'abc', 'Hello', '', null, new \DateTimeImmutable('+1 day'));
        foreach ($networks as $network) {
            new SocialPostTarget($post, $network, 'Hello');
        }
        foreach ($medias as [$mimeType, $size, $duration]) {
            $media = SocialMedia::reference($post, 'medias/x', $mimeType);
            $media->setSize($size)->setDimensions(null, null, $duration);
            $post->addMedia($media);
        }

        return $post;
    }

    /**
     * @param array<string, list<TranslatableMessage>> $problems
     *
     * @return array<string, list<string>>
     */
    private function keys(array $problems): array
    {
        return array_map(static fn (array $messages): array => array_map(static fn (TranslatableMessage $message): string => $message->getMessage(), $messages), $problems);
    }

    // Medias every network takes say nothing
    public function testMediasThatSuitSayNothing(): void
    {
        $this->assertSame([], $this->checker()->check($this->post(['bluesky', 'instagram'], [['image/jpeg', 500000, null], ['image/jpeg', 500000, null]])));
    }

    // Instagram takes no post without a picture or a video, Bluesky does
    public function testAMediaIsRequiredWhereTheNetworkSaysSo(): void
    {
        $this->assertSame(['instagram' => ['label.social_media_required']], $this->keys($this->checker()->check($this->post(['bluesky', 'instagram'], []))));
    }

    // Five pictures are one too many for Bluesky, a picture beside a video too, and a picture over its size
    public function testEachNetworkCountsAndWeighsByItsOwnRules(): void
    {
        $problems = $this->keys($this->checker()->check($this->post(['bluesky'], [
            ['image/jpeg', 3000000, null], ['image/jpeg', 1, null], ['image/jpeg', 1, null], ['image/jpeg', 1, null], ['image/jpeg', 1, null], ['video/mp4', 1, 20.0],
        ])));

        $this->assertSame(['bluesky' => ['label.social_media_too_many', 'label.social_media_no_mix', 'label.social_media_image_too_big']], $problems);
    }

    // A video is checked for its type and its duration, one ffprobe could not read stepping over the duration
    public function testAVideoIsCheckedForItsTypeAndDuration(): void
    {
        $this->assertSame(['bluesky' => ['label.social_media_video_type', 'label.social_media_video_too_long']], $this->keys($this->checker()->check($this->post(['bluesky'], [['video/quicktime', 1, 700.0]]))));
        $this->assertSame(['instagram' => ['label.social_media_video_too_short']], $this->keys($this->checker()->check($this->post(['instagram'], [['video/quicktime', 1, 2.0]]))));
        $this->assertSame([], $this->checker()->check($this->post(['instagram'], [['video/quicktime', 1, null]])));
    }

    // A network the post went out on already is not checked again, nor one the site does not post to
    public function testOnlyTheNetworksStillToGoOutAreChecked(): void
    {
        $post = $this->post(['instagram', 'mastodon'], []);
        $post->getTargets()->first()->markPublished('id');

        $this->assertSame([], $this->checker()->check($post));
    }
}
