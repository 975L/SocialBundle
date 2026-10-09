<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Form;

use c975L\SocialBundle\Form\SocialSeriesType;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

class SocialSeriesTypeTest extends TypeTestCase
{
    // A stub rather than TypeTestCase's own mock, which PHPUnit 13 flags as used without expectations
    protected function setUp(): void
    {
        $this->dispatcher = $this->createStub(EventDispatcherInterface::class);
        parent::setUp();
    }

    #[\Override]
    protected function getExtensions(): array
    {
        return [new ValidatorExtension(Validation::createValidator())];
    }

    // A group is named alone under the source heading it, the whole source marked as that heading
    public function testTheGroupsAreNamedUnderTheirSource(): void
    {
        $form = $this->factory->create(SocialSeriesType::class, null, ['networks' => ['bluesky'], 'sources' => ['gallery_media' => 'Gallery media', 'gallery_media:3' => 'Gallery media - Animaux']]);

        $labels = array_map(static fn ($choice): string => (string) $choice->label, $form->get('sources')->createView()->vars['choices']);
        $this->assertSame(['Gallery media', 'Animaux'], array_values($labels));
        $this->assertSame('social-series-source', $form->get('sources')->createView()->vars['choices'][0]->attr['class']);
    }

    // Some days of the week need at least one day ticked
    public function testSomeDaysOfTheWeekNeedADay(): void
    {
        $form = $this->factory->create(SocialSeriesType::class, null, ['networks' => ['bluesky']]);

        $form->submit(['count' => 3, 'start' => '2026-10-10T09:00', 'frequency' => 'weekdays', 'interval' => 1, 'networks' => ['bluesky'], 'mode' => 'source']);

        $this->assertFalse($form->isValid());
        $this->assertSame('label.social_series_weekdays_required', $form->getErrors(true)[0]->getMessageTemplate());
    }
}
