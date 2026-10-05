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
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vich\UploaderBundle\Form\Type\VichFileType;

use function Symfony\Component\Translation\t;

// One picture or video of a post on its screen: seen as it will go out, replaced by a new upload, described, ordered - removed with the collection's own button
class SocialMediaType extends AbstractType
{
    // What an upload may be: the pictures turned into a JPEG, and the videos the networks take
    private const array ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', ...SocialMedia::VIDEO_TYPES];

    // Above every network's own limit for a video, which the rules then say network by network
    private const string MAX_SIZE = '1024M';

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', VichFileType::class, $this->fileOptions())
            ->add('alt', TextType::class, [
                'label' => t('label.social_media_alt', [], 'social'),
                'required' => false,
            ])
            ->add('position', IntegerType::class, [
                'label' => t('label.social_media_position', [], 'social'),
                'required' => false,
            ]);

        // The media as it goes out, above its fields - a file of the site's own said so, it is changed where it is stored
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $media = $event->getData();
            $path = $media instanceof SocialMedia ? $media->getPublicPath() : null;
            if (null === $path) {
                return;
            }

            $preview = $media->isVideo()
                ? sprintf('<video src="%s" controls preload="metadata" class="social-media-preview"></video>', htmlspecialchars($path))
                : sprintf('<img src="%s" alt="" loading="lazy" class="social-media-preview">', htmlspecialchars($path));
            $note = $media->isReference() ? htmlspecialchars($this->translator->trans('help.social_media_reference', [], 'social')) : '';
            $event->getForm()->add('file', VichFileType::class, ['help' => $preview . $note, 'help_html' => true] + $this->fileOptions());
        });
    }

    /** @return array<string, mixed> */
    private function fileOptions(): array
    {
        return [
            'label' => t('label.social_media_file', [], 'social'),
            'required' => false,
            'allow_delete' => false,
            'download_uri' => false,
            'constraints' => [new File(maxSize: self::MAX_SIZE, mimeTypes: self::ACCEPTED_TYPES)],
            'attr' => ['accept' => implode(',', self::ACCEPTED_TYPES)],
        ];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SocialMedia::class,
            'label' => false,
        ]);
    }
}
