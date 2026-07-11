<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Form\Block;

use c975L\SocialBundle\Service\ShareButtonsServiceInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

// Dedicated block prefix ("share_buttons_style_preview") so share_buttons_style_preview_theme.html.twig
// can override just this field's widget - EasyAdmin's Field::setTemplatePath() only affects index/detail
// pages, never New/Edit (see ShareButtonsSettingsCrudController), so a plain, type-less field there
// always falls back to rendering as a real <input>
class ShareButtonsStylePreviewType extends AbstractType
{
    public function __construct(private readonly ShareButtonsServiceInterface $shareButtonsService)
    {
    }

    // Every known network is rendered into the preview, whichever are actually checked -
    // assets/js/share-buttons-preview.js hides/reorders them client-side to match the "networks"
    // checkboxes live, instead of this needing the entity's saved data at all
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['all_networks'] = $this->shareButtonsService->getNetworks();
    }

    public function getParent(): string
    {
        return TextType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'share_buttons_style_preview';
    }
}
