<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Form;

use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Form\SocialPostTargetType;
use c975L\SocialBundle\Service\SocialPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;
use Symfony\Contracts\Translation\TranslatorInterface;

class SocialPostTargetTypeTest extends TestCase
{
    // The message said under the text of a target on a network taking 300 characters
    private function help(SocialPostTarget $target): string
    {
        $publisher = $this->createStub(SocialPublisher::class);
        $publisher->method('getMaxLength')->willReturn(300);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $factory = Forms::createFormFactoryBuilder()->addType(new SocialPostTargetType($publisher, $translator))->getFormFactory();

        return $factory->create(SocialPostTargetType::class, $target)->get('text')->getConfig()->getOption('help')->getMessage();
    }

    private function target(): SocialPostTarget
    {
        return new SocialPostTarget(new SocialPost(SocialPost::SOURCE_MANUAL, 'id', 'Title', 'https://example.org/a', null, new \DateTimeImmutable()), 'bluesky', 'Text');
    }

    public function testATextIsToldItsLimit(): void
    {
        $this->assertSame('label.social_post_text_help', $this->help($this->target()));
    }

    // The text of a repost is never sent, which correcting it would otherwise hide
    public function testARepostSaysItsTextIsNeverSent(): void
    {
        $this->assertSame('label.social_post_repost_help', $this->help($this->target()->setRepost('other.bsky.social')));
    }

    // A refusal says why first, a repost being refused too
    public function testARefusalComesFirst(): void
    {
        $target = $this->target()->setRepost('other.bsky.social');
        $target->markFailed('Down');

        $this->assertSame('label.social_post_failed_help', $this->help($target));
    }
}
