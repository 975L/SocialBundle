<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Form\Block;

use c975L\UiBundle\Form\Block\HasAnchorFieldTrait;
use c975L\UiBundle\Service\BlockAnchorSlugger;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Event\PreSetDataEvent;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\PositiveOrZero;

// Data form of the "google_reviews" block kind - only how the imported Google reviews are shown: which of them is a matter of the reviews' own "displayed" switch, on UiBundle's moderation list
class GoogleReviewsType extends AbstractType
{
    use HasAnchorFieldTrait;

    // Seconds between two turns of the carousel, a figure an editor reads at a glance where the slider's milliseconds are not
    public const int DEFAULT_DURATION = 5;

    public function __construct(private readonly BlockAnchorSlugger $anchorSlugger)
    {
    }

    // The block's display settings, a new block starting on the default duration
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'label.google_reviews_title',
                'required' => false,
            ])
            ->add('duration', IntegerType::class, [
                'label' => 'label.google_reviews_duration',
                'help' => 'label.google_reviews_duration_help',
                'constraints' => [new PositiveOrZero()],
                'attr' => ['min' => 0],
            ])
            // Empty shows them all, newest first: the order never looks at the rating, a subset being defensible only when what picks it is blind to how good it is
            ->add('limit', IntegerType::class, [
                'label' => 'label.google_reviews_limit',
                'help' => 'label.google_reviews_limit_help',
                'required' => false,
                'constraints' => [new Positive()],
                'attr' => ['min' => 1],
            ])
        ;

        $this->addAnchorField($builder, $this->anchorSlugger);

        // Default for a brand new block only - the field's own "data" option would override the saved value on every edit
        $builder->addEventListener(
            FormEvents::PRE_SET_DATA,
            static function (PreSetDataEvent $event): void {
                $data = $event->getData();
                if (!is_array($data)) {
                    $data = [];
                }

                $data['duration'] ??= self::DEFAULT_DURATION;
                $event->setData($data);
            }
        );
    }

    // No data class, the block's data being an array, and the bundle's own translations
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'translation_domain' => 'social',
        ]);
    }
}
