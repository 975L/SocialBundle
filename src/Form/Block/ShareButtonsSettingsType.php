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
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Data form of the "share_buttons_settings" singleton (see ShareButtonsSettingsCrudController): the
// site-wide default networks/style used when share_buttons_default() auto-renders on every page
class ShareButtonsSettingsType extends AbstractType
{
    public function __construct(private readonly ShareButtonsServiceInterface $shareButtonsService)
    {
    }

    // Both fields are (re)built from scratch on PRE_SET_DATA, not added directly below - "networks"
    // needs the entity's currently saved order to sort its choices (see reorderNetworkChoices()), which
    // isn't available yet while the form is still being defined here. Building "style" here too, instead
    // of leaving it in a plain ->add() call, keeps it after "networks" in the rendered field order -
    // fields added from inside the listener are appended after any already added directly, which would
    // otherwise flip the two around.
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $networkChoices = array_combine(
            array_map(ucfirst(...), $this->shareButtonsService->getNetworks()),
            $this->shareButtonsService->getNetworks(),
        );

        $styleChoices = array_combine(
            array_map(static fn (string $style): string => 'label.style_' . $style, $this->shareButtonsService->getStyles()),
            $this->shareButtonsService->getStyles(),
        );

        $builder->addEventListener(
            FormEvents::PRE_SET_DATA,
            function (FormEvent $event) use ($networkChoices, $styleChoices): void {
                $data = $event->getData() ?? [];
                $savedOrder = is_array($data['networks'] ?? null) ? $data['networks'] : [];

                $event->getForm()
                    ->add('networks', ChoiceType::class, [
                        'label' => 'label.networks',
                        'choices' => $this->reorderNetworkChoices($networkChoices, $savedOrder),
                        'multiple' => true,
                        'expanded' => true,
                        'required' => false,
                        // Rendered as a draggable list, not ChoiceType's default expanded layout - see
                        // share_buttons_style_preview_theme.html.twig's "share_buttons_networks_widget"
                        // block and assets/js/share-buttons-networks-sort.js. Reordering it there changes
                        // the checkboxes' DOM order, which is what reorderNetworkChoices() above reads
                        // back on the next save (plain checkbox submission follows DOM order).
                        'block_prefix' => 'share_buttons_networks',
                    ])
                    ->add('style', ChoiceType::class, [
                        'label' => 'label.style',
                        'choices' => $styleChoices,
                        'expanded' => false,
                        // Hooked by assets/js/share-buttons-preview.js to refresh the live preview
                        // below this field (see ShareButtonsSettingsCrudController) on change
                        'attr' => ['data-share-style-select' => true],
                    ])
                ;
            },
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'translation_domain' => 'social',
        ]);
    }

    // Puts previously saved, checked networks first (in their saved order), then every other network
    // in ShareButtonsService::getNetworks()'s fixed order - without this, the sortable list would reset
    // to that fixed order on every page load, discarding whatever order was last dragged and saved.
    private function reorderNetworkChoices(array $networkChoices, array $savedOrder): array
    {
        if ([] === $savedOrder) {
            return $networkChoices;
        }

        $ordered = [];
        foreach (array_unique([...$savedOrder, ...array_values($networkChoices)]) as $network) {
            $label = array_search($network, $networkChoices, true);
            if (false !== $label) {
                $ordered[$label] = $network;
            }
        }

        return $ordered;
    }
}
