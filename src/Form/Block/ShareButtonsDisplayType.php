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
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Data form of the "share_buttons_display" block kind - carries no display data of its own: this block only points at the site-wide "share_buttons_settings" singleton (see ShareButtonsSettingsCrudController). Its single field is the in-page anchor every other section-level kind offers (see UiBundle's README, "Anchors"), so a menu entry can link straight to the share band instead of the block being unreachable from a navbar
class ShareButtonsDisplayType extends AbstractType
{
    use HasAnchorFieldTrait;

    public function __construct(private readonly BlockAnchorSlugger $anchorSlugger)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // No "title" field on this kind - the anchor must be typed explicitly, no slug fallback (same as UiBundle's FeatureBarType)
        $this->addAnchorField($builder, $this->anchorSlugger);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'translation_domain' => 'social',
        ]);
    }
}
