<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Form\Block;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Data form of the "social_links" block kind - the list of links is a plain array stored in the
// Block's JSON "data" column (data.links), not a separate entity/table (see SocialLinkEntryType)
class SocialLinksType extends AbstractType
{
    // Matches the ".social-links--{style}" variants styled in sass/_social.scss
    private const ICON_STYLES = ['minimal', 'colored', 'outline'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Whether every icon keeps its plain, monochrome color (inheriting the surrounding text
            // color), gets a solid brand-colored badge behind it (white icon on top), or a lighter
            // brand-colored ring that fills in on hover - same Font Awesome glyph in every case (see
            // SocialLinkExtension::getSocialLinkIcon()), just recolored via CSS (sass/_social.scss),
            // mirroring share_buttons()'s own per-network coloring/styles
            ->add('iconStyle', ChoiceType::class, [
                'label' => 'label.icon_style',
                'choices' => array_combine(
                    array_map(static fn (string $style): string => 'label.icon_style_' . $style, self::ICON_STYLES),
                    self::ICON_STYLES,
                ),
                'expanded' => false,
                // Hooked by assets/js/social-links-preview.js to refresh the live preview below (see
                // SocialLinksCrudController) on change
                'attr' => ['data-social-links-icon-style-select' => true],
            ])
            // Whether the label is rendered as visible text next to the icon, for every entry - still
            // used as aria-label/alt regardless. No empty_data: an unchecked checkbox submits no value
            // at all, indistinguishable from "never touched" - empty_data would force it back to true
            // on every submit, making it impossible to actually uncheck it once saved.
            ->add('displayLabel', CheckboxType::class, [
                'label' => 'label.display_label',
                'required' => false,
                // Hooked by assets/js/social-links-preview.js to refresh the live preview below (see
                // SocialLinksCrudController) on change
                'attr' => ['data-social-links-display-label-checkbox' => true],
            ])
            ->add('links', CollectionType::class, [
                // No label: the parent HiddenField (SocialLinksCrudController) already carries
                // "label.social_links" as the section title, a second one here would just duplicate it
                'label' => false,
                'entry_type' => SocialLinkEntryType::class,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'translation_domain' => 'social',
        ]);
    }
}
