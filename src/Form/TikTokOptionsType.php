<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Form;

use c975L\SocialBundle\Service\TikTokClient;
use c975L\SocialBundle\Service\TikTokPublisher;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function Symfony\Component\Translation\t;

// TikTok's own screen, as its Content Sharing Guidelines require it before a post: the account it goes out on, a privacy picked among the ones the account offers with none picked beforehand, the interactions off until ticked and greyed when the account turned them off, the commercial disclosure, then the consent - read live from TikTok each time the post's screen opens
class TikTokOptionsType extends AbstractType
{
    // TikTok's policies the consent refers to
    public const string MUSIC_USAGE = 'https://www.tiktok.com/legal/page/global/music-usage-confirmation/en';

    public const string BRANDED_CONTENT = 'https://www.tiktok.com/legal/page/global/bc-policy/en';

    public function __construct(
        private readonly TikTokClient $tikTokClient,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // An account TikTok does not answer for still shows the choices made, its reason said
        $error = null;
        try {
            $creator = $this->tikTokClient->creatorInfo();
        } catch (\RuntimeException $exception) {
            $creator = ['nickname' => '', 'privacy_options' => [], 'comment_disabled' => false, 'duet_disabled' => false, 'stitch_disabled' => false, 'max_duration' => null];
            $error = $exception->getMessage();
        }

        $builder
            ->add('privacy', ChoiceType::class, [
                'label' => t('label.social_tiktok_privacy', [], 'social'),
                'help' => null === $error
                    ? t('help.social_tiktok_account', ['%name%' => $creator['nickname']], 'social')
                    : t('label.social_tiktok_cannot_post', ['%error%' => $error], 'social'),
                'choices' => array_combine(
                    array_map(static fn (string $privacy): string => 'label.social_tiktok_privacy_' . strtolower($privacy), $creator['privacy_options']),
                    $creator['privacy_options'],
                ),
                'choice_translation_domain' => 'social',
                'placeholder' => t('label.social_tiktok_privacy_pick', [], 'social'),
                // Greyed while TikTok does not answer, so a save then keeps the privacy picked rather than emptying it
                'disabled' => null !== $error,
                'required' => false,
            ])
            ->add('comment', CheckboxType::class, $this->interaction('comment', $creator['comment_disabled']))
            ->add('duet', CheckboxType::class, $this->interaction('duet', $creator['duet_disabled']))
            ->add('stitch', CheckboxType::class, $this->interaction('stitch', $creator['stitch_disabled']))
            ->add('commercial', CheckboxType::class, [
                'label' => t('label.social_tiktok_commercial', [], 'social'),
                'help' => t('help.social_tiktok_commercial', [], 'social'),
                'required' => false,
            ])
            ->add('brand_organic', CheckboxType::class, [
                'label' => t('label.social_tiktok_brand_organic', [], 'social'),
                'help' => t('help.social_tiktok_brand_organic', [], 'social'),
                'required' => false,
            ])
            ->add('brand_content', CheckboxType::class, [
                'label' => t('label.social_tiktok_brand_content', [], 'social'),
                'help' => t('help.social_tiktok_brand_content', [], 'social'),
                'required' => false,
            ])
            ->add('consent', CheckboxType::class, [
                'label' => t('label.social_tiktok_consent', ['%music%' => self::MUSIC_USAGE, '%branded%' => self::BRANDED_CONTENT], 'social'),
                'label_html' => true,
                'help' => t('help.social_tiktok_processing', [], 'social'),
                'required' => false,
            ]);

        $builder->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event) use ($creator): void {
            $data = $event->getData();
            if (!\is_array($data)) {
                return;
            }

            // An interaction the account turned off is saved off, a greyed box otherwise keeping what was ticked before
            foreach (['comment', 'duet', 'stitch'] as $name) {
                if ($creator[$name . '_disabled']) {
                    $data[$name] = false;
                }
            }

            // A branded content cannot be private: switched to public when the account offers it, which the help under the privacy says - a branded content left ticked under an unticked disclosure saying nothing
            if (true === ($data['commercial'] ?? false) && true === ($data['brand_content'] ?? false) && TikTokPublisher::PRIVATE === ($data['privacy'] ?? null) && \in_array('PUBLIC_TO_EVERYONE', $creator['privacy_options'], true)) {
                $data['privacy'] = 'PUBLIC_TO_EVERYONE';
            }

            $event->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'label' => t('label.social_tiktok_options', [], 'social'),
        ]);
    }

    // One interaction, off by default, greyed with its reason when the account turned it off in TikTok
    /** @return array<string, mixed> */
    private function interaction(string $name, bool $disabled): array
    {
        return [
            'label' => t('label.social_tiktok_' . $name, [], 'social'),
            'help' => $disabled ? t('help.social_tiktok_interaction_disabled', [], 'social') : null,
            'disabled' => $disabled,
            'required' => false,
        ];
    }
}
