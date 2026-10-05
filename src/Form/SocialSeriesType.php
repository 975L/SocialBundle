<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Form;

use c975L\SocialBundle\Entity\SocialMedia;
use c975L\SocialBundle\Service\SocialPlanner;
use c975L\SocialBundle\Service\SocialSeriesGenerator;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function Symfony\Component\Translation\t;

// What a series of drafts is (see SocialSeriesGenerator): how many, from when, at which pace, on which networks, and its texts - one text, an instruction for the site's AI, or the sources' next contents - a picture or a video going with every draft written here
class SocialSeriesType extends AbstractType
{
    // A series is generated again once it has gone out, rather than planned for a year
    private const int MAX_COUNT = 31;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('count', IntegerType::class, [
                'label' => t('label.social_series_count', [], 'social'),
                'data' => 7,
                'constraints' => [new NotBlank(), new Range(min: 1, max: self::MAX_COUNT)],
            ])
            ->add('start', DateTimeType::class, [
                'label' => t('label.social_series_start', [], 'social'),
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'data' => $options['start'],
                'attr' => ['step' => SocialPlanner::QUARTER],
                'constraints' => [new NotBlank()],
            ])
            ->add('frequency', ChoiceType::class, [
                'label' => t('label.social_series_frequency', [], 'social'),
                'choices' => array_combine(array_map(static fn (string $frequency): string => 'label.social_series_frequency_' . $frequency, array_keys(SocialSeriesGenerator::FREQUENCIES)), array_keys(SocialSeriesGenerator::FREQUENCIES)),
                'choice_translation_domain' => 'social',
            ])
            ->add('networks', ChoiceType::class, [
                'label' => t('label.social_post_send_on', [], 'social'),
                'choices' => array_combine(array_map(ucfirst(...), $options['networks']), $options['networks']),
                'data' => $options['networks'],
                'multiple' => true,
                'expanded' => true,
                'constraints' => [new Count(min: 1)],
            ])
            ->add('mode', ChoiceType::class, [
                'label' => t('label.social_series_mode', [], 'social'),
                'choices' => [
                    'label.social_series_mode_text' => SocialSeriesGenerator::MODE_TEXT,
                    'label.social_series_mode_ai' => SocialSeriesGenerator::MODE_AI,
                    'label.social_series_mode_source' => SocialSeriesGenerator::MODE_SOURCE,
                ],
                'choice_translation_domain' => 'social',
                'data' => SocialSeriesGenerator::MODE_TEXT,
                'expanded' => true,
            ])
            // The generic text, or the instruction the AI writes each text from - Donovan under it either way
            ->add('text', TextareaType::class, [
                'label' => t('label.social_series_text', [], 'social'),
                'help' => t('help.social_series_text', [], 'social'),
                'required' => false,
                'attr' => ['rows' => 6, 'data-ai-rephrase' => true],
            ])
            ->add('sources', ChoiceType::class, [
                'label' => t('label.social_series_sources', [], 'social'),
                'help' => t('help.social_series_sources', [], 'social'),
                'choices' => array_flip($options['sources']),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
            ])
            ->add('media', FileType::class, [
                'label' => t('label.social_series_media', [], 'social'),
                'help' => t('help.social_series_media', [], 'social'),
                'required' => false,
                'constraints' => [new File(mimeTypes: ['image/jpeg', 'image/png', 'image/webp', 'image/gif', ...SocialMedia::VIDEO_TYPES])],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'networks' => [],
            'sources' => [],
            'start' => null,
            // What each mode needs: a text for one text or the AI, nothing more for the sources
            'constraints' => [new Callback(static function (mixed $data, ExecutionContextInterface $context): void {
                if (\is_array($data) && SocialSeriesGenerator::MODE_SOURCE !== ($data['mode'] ?? null) && '' === trim((string) ($data['text'] ?? ''))) {
                    $context->buildViolation('label.social_series_text_required')->setTranslationDomain('social')->atPath('[text]')->addViolation();
                }
            })],
        ]);
        $resolver->setAllowedTypes('networks', 'array');
        $resolver->setAllowedTypes('sources', 'array');
    }
}
