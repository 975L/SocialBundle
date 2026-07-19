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
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Dedicated block prefix ("social_links_preview") so social_links_preview_theme.html.twig can override just this field's widget - EasyAdmin's Field::setTemplatePath() only affects index/detail pages, never New/Edit (see ShareButtonsSettingsCrudController's ShareButtonsStylePreviewType, the same pattern this mirrors). Static, unlike that one: it renders the entry list as last saved (SocialLinksCrudController reads it straight off the entity), it doesn't live-update as entries are added/edited/removed in the form above - keeping that in sync would need re-running the whole "links" CollectionType client-side.
class SocialLinksPreviewType extends AbstractType
{
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['links'] = $options['links'];
        $view->vars['display_label'] = $options['display_label'];
        $view->vars['icon_style'] = $options['icon_style'];
    }

    public function getParent(): string
    {
        return TextType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'social_links_preview';
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'links' => [],
            'display_label' => true,
            'icon_style' => 'minimal',
        ]);
        $resolver->setAllowedTypes('links', 'array');
        $resolver->setAllowedTypes('display_label', 'bool');
        $resolver->setAllowedTypes('icon_style', 'string');
    }
}
