<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Form;

use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Entity\SocialSlot;
use c975L\SocialBundle\Service\SocialSeriesGenerator;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

// A slot of the template week (see SocialSlot): its name, its pace and what the pace needs, the quarter of an hour it goes out at, the key an import names it by and the series prolonged on it
class SocialSlotType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => t('label.social_slot_label', [], 'social'),
                'attr' => ['maxlength' => 100],
                'empty_data' => '',
            ])
            ->add('pace', ChoiceType::class, [
                'label' => t('label.social_slot_pace', [], 'social'),
                'choices' => array_combine(array_map(static fn (string $pace): string => 'label.social_slot_pace_' . $pace, SocialSlot::PACES), SocialSlot::PACES),
                'choice_translation_domain' => 'social',
            ])
            ->add('weekday', ChoiceType::class, [
                'label' => t('label.social_slot_weekday', [], 'social'),
                'choices' => array_combine(array_map(static fn (int $day): string => 'label.social_series_weekday_' . $day, range(1, 7)), range(1, 7)),
                'choice_translation_domain' => 'social',
                'required' => false,
                'help' => t('help.social_slot_weekday', [], 'social'),
            ])
            // Every quarter of an hour of the day, the step the planned run sends posts at
            ->add('time', ChoiceType::class, [
                'label' => t('label.social_slot_time', [], 'social'),
                'choices' => array_combine($this->quarters(), $this->quarters()),
            ])
            ->add('rank', ChoiceType::class, [
                'label' => t('label.social_slot_rank', [], 'social'),
                'choices' => array_combine(array_map(static fn (int $rank): string => 'label.social_slot_rank_' . (SocialSlot::LAST === $rank ? 'last' : $rank), SocialSlot::RANKS), SocialSlot::RANKS),
                'choice_translation_domain' => 'social',
                'required' => false,
                'help' => t('help.social_slot_rank', [], 'social'),
            ])
            ->add('monthDay', IntegerType::class, [
                'label' => t('label.social_slot_month_day', [], 'social'),
                'required' => false,
                'attr' => ['min' => 1, 'max' => 31],
                'help' => t('help.social_slot_month_day', [], 'social'),
            ])
            ->add('startsOn', DateType::class, [
                'label' => t('label.social_slot_starts_on', [], 'social'),
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'help' => t('help.social_slot_starts_on', [], 'social'),
            ])
            ->add('slotKey', TextType::class, [
                'label' => t('label.social_slot_key', [], 'social'),
                'required' => false,
                'attr' => ['maxlength' => 64, 'pattern' => '[a-z0-9][a-z0-9\-]*'],
                'help' => t('help.social_slot_key', [], 'social'),
            ])
            // Only a series prolonged on its own pace: an imported one takes its slots from its file
            ->add('series', EntityType::class, [
                'label' => t('label.social_slot_series', [], 'social'),
                'class' => SocialSeries::class,
                'choice_label' => 'title',
                'required' => false,
                'query_builder' => static fn (EntityRepository $repository): QueryBuilder => $repository->createQueryBuilder('s')
                    ->where('s.mode <> :import')
                    ->setParameter('import', SocialSeriesGenerator::MODE_IMPORT)
                    ->orderBy('s.title', 'ASC'),
                'help' => t('help.social_slot_series', [], 'social'),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SocialSlot::class]);
    }

    // The 96 quarters of an hour of a day, written HH:MM
    /** @return list<string> */
    private function quarters(): array
    {
        return array_map(static fn (int $minutes): string => sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60), range(0, 23 * 60 + 45, 15));
    }
}
