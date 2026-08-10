<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Form\Block;

use c975L\UiBundle\Form\TrixEditorType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Data form of the "social_links" block kind - the list of links is a plain array stored in the Block's JSON "data" column (data.links), not a separate entity/table (see SocialLinkEntryType)
class SocialLinksType extends AbstractType
{
    // Matches the ".social-links--{style}" variants styled in sass/_social.scss. Public: the block showcase renders one card per style (see GalleryShowcaseProvider) and must not carry a second copy of the list
    public const ICON_STYLES = ['minimal', 'colored', 'outline', 'text'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Optional lead-in rendered centered above the icon row (see templates/blocks/SocialLinks.html.twig) - left empty, nothing at all is rendered, not even an empty wrapper. TrixEditorType, the editor every other rich-text field of the ecosystem uses: its widget/assets are registered dashboard-wide (see ConfigBundle's DashboardController), so nothing extra is needed here
            ->add('intro', TrixEditorType::class, [
                'label' => 'label.intro',
                'help' => 'label.intro_help',
                'required' => false,
            ])
            // Whether every icon keeps its plain, monochrome color (inheriting the surrounding text color), gets a solid brand-colored badge behind it (white icon on top), or a lighter brand-colored ring that fills in on hover - same Font Awesome glyph in every case (see SocialLinkExtension::getSocialLinkIcon()), just recolored via CSS (sass/_social.scss), mirroring share_buttons()'s own per-network coloring/styles
            // "text" is the one that shows no glyph at all, the network's name standing as the link - a footer row set as words, where a row of marks would compete with the site's own. It prints the label whatever the box below says (see templates/blocks/SocialLinks.html.twig): with the icon gone, an entry without it would have nothing left to click
            ->add('iconStyle', ChoiceType::class, [
                'label' => 'label.icon_style',
                'choices' => array_combine(
                    array_map(static fn (string $style): string => 'label.icon_style_' . $style, self::ICON_STYLES),
                    self::ICON_STYLES,
                ),
                'expanded' => false,
                // Hooked by assets/js/social-links-preview.js to refresh the live preview below (see SocialLinksCrudController) on change
                'attr' => ['data-social-links-icon-style-select' => true],
            ])
            // Whether the label is rendered as visible text next to the icon, for every entry - still used as aria-label/alt regardless. No empty_data: an unchecked checkbox submits no value at all, indistinguishable from "never touched" - empty_data would force it back to true on every submit, making it impossible to actually uncheck it once saved.
            ->add('displayLabel', CheckboxType::class, [
                'label' => 'label.display_label',
                'required' => false,
                // Hooked by assets/js/social-links-preview.js to refresh the live preview below (see SocialLinksCrudController) on change
                'attr' => ['data-social-links-display-label-checkbox' => true],
            ])
            ->add('links', CollectionType::class, [
                // No label: the parent HiddenField (SocialLinksCrudController) already carries "label.social_links" as the section title, a second one here would just duplicate it
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
