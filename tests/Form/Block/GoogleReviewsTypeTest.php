<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Form\Block;

use c975L\SocialBundle\Form\Block\GoogleReviewsType;
use c975L\UiBundle\Service\BlockAnchorSlugger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\Validation;

class GoogleReviewsTypeTest extends TypeTestCase
{
    // Pre-seeds a stub before TypeTestCase::setUp() runs, which would otherwise create a mock with no expectations
    protected function setUp(): void
    {
        $this->dispatcher = $this->createStub(EventDispatcherInterface::class);
        parent::setUp();
    }

    #[\Override]
    protected function getTypes(): array
    {
        return [new GoogleReviewsType(new BlockAnchorSlugger(new AsciiSlugger()))];
    }

    // The "constraints" option the duration and the limit carry
    #[\Override]
    protected function getExtensions(): array
    {
        return [new ValidatorExtension(Validation::createValidator())];
    }

    // How the reviews are shown, never which: that is the moderation list's "displayed" switch
    public function testBuildFormAddsTheDisplaySettingsAndTheAnchor(): void
    {
        $form = $this->factory->create(GoogleReviewsType::class);

        $this->assertSame(['title', 'duration', 'limit', 'anchor'], array_keys($form->all()));
    }

    // A new block plays on its own, five seconds a turn
    public function testANewBlockTurnsEveryFiveSeconds(): void
    {
        $form = $this->factory->create(GoogleReviewsType::class);

        $this->assertSame(GoogleReviewsType::DEFAULT_DURATION, $form->get('duration')->getData());
    }

    // A duration already saved is kept, 0 included - the field's own "data" option would reset it on every edit
    public function testASavedDurationIsKept(): void
    {
        $form = $this->factory->create(GoogleReviewsType::class, ['duration' => 0]);

        $this->assertSame(0, $form->get('duration')->getData());
    }

    // The anchor falls back on the title, as on every section kind
    public function testTheAnchorIsSluggedFromTheTitle(): void
    {
        $form = $this->factory->create(GoogleReviewsType::class);
        $form->submit(['title' => 'Nos avis', 'duration' => '5', 'limit' => '', 'anchor' => '']);

        $this->assertSame('nos-avis', $form->getData()['anchor']);
        $this->assertNull($form->getData()['limit']);
    }

    // A limit of nothing or less would hide every review behind a block still printing its average
    public function testALimitBelowOneIsRefused(): void
    {
        $form = $this->factory->create(GoogleReviewsType::class);
        $form->submit(['title' => '', 'duration' => '5', 'limit' => '0', 'anchor' => '']);

        $this->assertFalse($form->isValid());
    }

    public function testConfigureOptionsUsesSocialTranslationDomainAndNoDataClass(): void
    {
        $form = $this->factory->create(GoogleReviewsType::class);

        $this->assertNull($form->getConfig()->getOption('data_class'));
        $this->assertSame('social', $form->getConfig()->getOption('translation_domain'));
    }
}
