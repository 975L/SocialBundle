<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Form;

use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Enum\SocialPostStatus;
use c975L\SocialBundle\Service\SocialPublisher;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

// One network's text on the post's screen, labelled with the network and its limit - where the text a network will receive is read and corrected before "Publish"
class SocialPostTargetType extends AbstractType
{
    public function __construct(
        private readonly SocialPublisher $socialPublisher,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Built once the target is known, the label, the limit and whether the text may still change all depending on it
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $target = $event->getData();
            if (!$target instanceof SocialPostTarget) {
                return;
            }

            $maxLength = $this->socialPublisher->getMaxLength($target->getNetwork());
            $help = null === $maxLength ? null : t('label.social_post_text_help', ['%max%' => $maxLength], 'social');
            if (SocialPostStatus::Failed === $target->getStatus()) {
                $help = t('label.social_post_failed_help', ['%error%' => (string) $target->getError()], 'social');
            }

            $event->getForm()->add('text', TextareaType::class, [
                'label' => ucfirst($target->getNetwork()) . ' - ' . $target->getStatus()->trans($this->translator),
                'help' => $help,
                // A published text is what the network shows: changing it here would change nothing there
                'disabled' => SocialPostStatus::Published === $target->getStatus(),
                // Opt-in marker read by the block form theme, which is what puts Donovan under a plain textarea
                'attr' => array_filter(['maxlength' => $maxLength, 'rows' => 6, 'data-ai-rephrase' => true]),
            ]);

            // Offered on a network answering its posts with a first comment alone, where the link then goes rather than in the text
            if ($this->socialPublisher->supportsComment($target->getNetwork())) {
                $event->getForm()->add('comment', TextareaType::class, [
                    'label' => t('label.social_post_comment', [], 'social'),
                    'help' => null === $target->getCommentError() ? t('help.social_post_comment', [], 'social') : t('email.social_post_comment_failed', ['%error%' => $target->getCommentError()], 'social'),
                    'required' => false,
                    'disabled' => null !== $target->getCommentId(),
                    'attr' => ['rows' => 2],
                ]);
            }

            // Offered on a network posting as a Page, the text the post is shared again with on the member's own profile - left empty, it is not. Still shown while a text is held, for one the network no longer takes to be emptied
            if ($this->socialPublisher->supportsReshare($target->getNetwork()) || null !== $target->getReshare()) {
                $event->getForm()->add('reshare', TextareaType::class, [
                    'label' => t('label.social_post_reshare', [], 'social'),
                    'help' => null === $target->getReshareError() ? t('help.social_post_reshare', [], 'social') : t('email.social_post_reshare_failed', ['%error%' => $target->getReshareError()], 'social'),
                    'required' => false,
                    'disabled' => null !== $target->getReshareId(),
                    'attr' => ['rows' => 2],
                ]);
            }

            // Offered on a network showing the post's picture as a story too, for 24 hours and without a link to the post
            if ($this->socialPublisher->supportsStory($target->getNetwork())) {
                $event->getForm()->add('story', CheckboxType::class, [
                    'label' => t('label.social_post_story', [], 'social'),
                    'help' => null === $target->getStoryError() ? t('help.social_post_story', [], 'social') : t('email.social_post_story_failed', ['%error%' => $target->getStoryError()], 'social'),
                    'required' => false,
                    'disabled' => null !== $target->getStoryId(),
                ]);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SocialPostTarget::class,
            'label' => false,
        ]);
    }
}
