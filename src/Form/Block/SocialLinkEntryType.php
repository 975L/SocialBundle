<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Form\Block;

use c975L\UiBundle\Form\IconPickerType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// One entry of SocialLinksType::$links - plain array (no entity, stored straight into the parent
// Block's JSON "data" column). "network" drives label/icon at render time (see SocialLinkExtension
// and SocialLinks.html.twig), so they're never stored here - leaving it empty is the escape hatch
// for a network with no icon in public/icons/ (or bundles/*/icons/), via customLabel/customIcon.
class SocialLinkEntryType extends AbstractType
{
    // Curated on purpose, not the full IconServiceInterface::getIcons(): that also serves UiBundle's
    // generic UI glyphs (alerts, faces, arrows...), which have no business showing up as a "network"
    // choice. Every key here is expected to have a public/icons/{key}.svg (see README).
    private const NETWORKS = [
        'behance', 'blogger', 'bluesky', 'buffer', 'delicious', 'discord', 'dribbble', 'email',
        'evernote', 'facebook', 'flickr', 'github', 'instagram', 'line', 'linkedin', 'mastodon',
        'medium', 'messenger', 'pinterest', 'reddit', 'skype', 'snapchat', 'soundcloud', 'spotify',
        'stumbleupon', 'telegram', 'threads', 'tiktok', 'tumblr', 'twitch', 'vimeo', 'vk', 'wechat',
        'whatsapp', 'wordpress', 'xing', 'youtube',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Same searchable icon-grid widget as customIcon below, restricted to NETWORKS and
            // storing the bare key ("facebook") instead of an icon path - see IconPickerType's
            // "icons"/"value_field" options
            ->add('network', IconPickerType::class, [
                'label' => 'label.network',
                'required' => false,
                'icons' => self::NETWORKS,
                'value_field' => 'name',
                // Read by assets/js/social-link-network-toggle.js to show/hide customLabel/customIcon
                'attr' => ['data-social-link-network-select' => true],
            ])
            ->add('url', UrlType::class, [
                'label' => 'label.url',
            ])
            // Only used when network is left empty - see social_link_entry_form_theme.html.twig
            ->add('customLabel', TextType::class, [
                'label' => 'label.label',
                'required' => false,
            ])
            ->add('customIcon', IconPickerType::class, [
                'label' => 'label.icon',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'label' => false,
            'translation_domain' => 'social',
        ]);
    }
}
