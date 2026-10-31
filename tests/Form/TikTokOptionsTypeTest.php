<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Form;

use c975L\SocialBundle\Form\TikTokOptionsType;
use c975L\SocialBundle\Service\TikTokClient;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;

class TikTokOptionsTypeTest extends TypeTestCase
{
    private ?\RuntimeException $refusal = null;

    // A stub rather than TypeTestCase's own mock, which PHPUnit 13 flags as used without expectations
    protected function setUp(): void
    {
        $this->dispatcher = $this->createStub(EventDispatcherInterface::class);
        parent::setUp();
    }

    // The account offering a public and a private video, its duets turned off
    #[\Override]
    protected function getExtensions(): array
    {
        $client = $this->createStub(TikTokClient::class);
        if (null === $this->refusal) {
            $client->method('creatorInfo')->willReturn(['nickname' => 'Mystic', 'privacy_options' => ['PUBLIC_TO_EVERYONE', 'SELF_ONLY'], 'comment_disabled' => false, 'duet_disabled' => true, 'stitch_disabled' => false, 'max_duration' => 180]);
        } else {
            $client->method('creatorInfo')->willThrowException($this->refusal);
        }

        return [new PreloadedExtension([new TikTokOptionsType($client)], [])];
    }

    // The privacies the account offers, none picked beforehand, the interactions off, the one the account turned off greyed
    public function testTheScreenFollowsTheAccount(): void
    {
        $form = $this->factory->create(TikTokOptionsType::class);
        $view = $form->createView();

        $this->assertSame(['PUBLIC_TO_EVERYONE', 'SELF_ONLY'], array_map(static fn ($choice): string => $choice->value, $view['privacy']->vars['choices']));
        $this->assertSame('', $view['privacy']->vars['value']);
        $this->assertSame('help.social_tiktok_account', $view['privacy']->vars['help']->getMessage());
        $this->assertTrue($view['duet']->vars['disabled']);
        $this->assertFalse($view['comment']->vars['disabled']);
        $this->assertFalse($view['comment']->vars['checked']);
    }

    // An interaction the account turned off is saved off, whatever was ticked before
    public function testAnInteractionTurnedOffIsSavedOff(): void
    {
        $form = $this->factory->create(TikTokOptionsType::class, ['privacy' => 'SELF_ONLY', 'duet' => true, 'consent' => true]);

        $form->submit(['privacy' => 'SELF_ONLY', 'comment' => '1', 'consent' => '1']);

        $this->assertFalse($form->getData()['duet']);
        $this->assertTrue($form->getData()['comment']);
    }

    // A branded content cannot be private: switched to public, the account offering it
    public function testABrandedContentIsMadePublic(): void
    {
        $form = $this->factory->create(TikTokOptionsType::class);

        $form->submit(['privacy' => 'SELF_ONLY', 'commercial' => '1', 'brand_content' => '1', 'consent' => '1']);

        $this->assertSame('PUBLIC_TO_EVERYONE', $form->getData()['privacy']);
        $this->assertTrue($form->getData()['consent']);
    }

    // A branded content left ticked under an unticked disclosure keeps the privacy picked
    public function testABrandedContentWithoutDisclosureStaysPrivate(): void
    {
        $form = $this->factory->create(TikTokOptionsType::class);

        $form->submit(['privacy' => 'SELF_ONLY', 'brand_content' => '1', 'consent' => '1']);

        $this->assertSame('SELF_ONLY', $form->getData()['privacy']);
    }

    // An account TikTok does not answer for still shows the form, its reason said
    public function testAnAccountThatCannotPostSaysWhy(): void
    {
        $this->refusal = new \RuntimeException('spam_risk_too_many_posts');
        parent::setUp();

        $view = $this->factory->create(TikTokOptionsType::class)->createView();

        $this->assertSame('label.social_tiktok_cannot_post', $view['privacy']->vars['help']->getMessage());
        $this->assertSame([], $view['privacy']->vars['choices']);
    }

    // A save while TikTok does not answer keeps the privacy picked
    public function testAnAccountThatCannotPostKeepsThePrivacy(): void
    {
        $this->refusal = new \RuntimeException('spam_risk_too_many_posts');
        parent::setUp();
        $form = $this->factory->create(TikTokOptionsType::class, ['privacy' => 'SELF_ONLY', 'consent' => true]);

        $form->submit(['consent' => '1']);

        $this->assertSame('SELF_ONLY', $form->getData()['privacy']);
    }
}
