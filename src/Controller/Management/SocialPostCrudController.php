<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Controller\Management;

use c975L\ConfigBundle\Management\EasyAdminActionHelper;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Enum\SocialPostStatus;
use c975L\SocialBundle\Form\SocialPostTargetType;
use c975L\SocialBundle\Service\SocialPublisher;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

// Where the posts prepared for the networks are read, corrected and sent - each network's text on its own, "Publish" sending every one not out yet. Posts are prepared by the hourly run, or here on demand (the next content, or any page by its address) rather than typed in; deleting one is cancelling it
class SocialPostCrudController extends AbstractCrudController
{
    // The one token "Publish" and the two ways of preparing a post are checked against
    private const string PUBLISH_CSRF_TOKEN = 'social_post_publish';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly SocialPublisher $socialPublisher,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return SocialPost::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(t('label.social_post', [], 'social'))
            ->setEntityLabelInPlural(t('label.social_posts', [], 'social'))
            ->setEntityPermission($this->configService->get('site-role-editor'))
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->showEntityActionsInlined()
            // Carries the screen's own explanatory text, the very key the sidebar entry reuses as its onboarding description (see MenuProvider)
            ->overrideTemplate('crud/index', '@c975LSocial/management/social_post_crud_index.html.twig')
        ;
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        $role = $this->configService->get('site-role-editor');

        // Built as an url rather than linked to the crud action, so the csrf token it checks travels with it: it posts under the site's name, and a link that does is a link somebody else's page can make the editor follow
        $publish = Action::new('publishPost', t('label.social_post_publish', [], 'social'), 'fa fa-paper-plane')
            ->linkToUrl(fn (SocialPost $post): string => $this->publishUrl($post))
            ->displayIf(static fn (SocialPost $post): bool => $post->getTargets()->exists(static fn (int $key, $target): bool => $target->isPending()))
        ;

        // The hourly run's own gesture, taken now: the next content, whatever the interval
        $prepareNext = Action::new('prepareNextPost', t('label.social_post_prepare_next', [], 'social'), 'fa fa-wand-magic-sparkles')
            ->linkToUrl(fn (): string => $this->actionUrl('prepareNextPost'))
            ->createAsGlobalAction()
        ;
        // Any page, of this site or another one, read from its Open Graph tags
        $prepareUrl = Action::new('prepareUrlPost', t('label.social_post_prepare_url', [], 'social'), 'fa fa-link')
            ->linkToUrl(fn (): string => $this->actionUrl('prepareUrlPost'))
            ->createAsGlobalAction()
        ;

        return $actions
            ->setPermission(Action::INDEX, $role)
            ->setPermission(Action::EDIT, $role)
            ->setPermission(Action::DELETE, $role)
            ->setPermission('publishPost', $role)
            ->setPermission('prepareNextPost', $role)
            ->setPermission('prepareUrlPost', $role)
            ->add(Crud::PAGE_INDEX, $prepareNext)
            ->add(Crud::PAGE_INDEX, $prepareUrl)
            ->disable(Action::NEW, Action::DETAIL)
            // From the list only: on the post's page, a link would send the text as saved, not as just corrected
            ->add(Crud::PAGE_INDEX, $publish)
            ->update(Crud::PAGE_INDEX, 'publishPost', fn (Action $action) => EasyAdminActionHelper::toIconOnly($action, $this->translator->trans('label.social_post_publish', [], 'social')))
        ;
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield DateTimeField::new('createdAt', t('label.social_post_created_at', [], 'social'))->setDisabled();
        yield TextField::new('title', t('label.social_post_title', [], 'social'))->setDisabled()->onlyOnForms();
        // The title with each network's status under it, what waits for a decision being why the list is opened. Anchored on the title rather than on the targets: a TextField refuses a collection before any formatValue() is called
        yield TextField::new('title', t('label.social_post_title', [], 'social'))
            ->formatValue(fn (mixed $value, SocialPost $post): string => $this->thumbnail($post, 80) . htmlspecialchars($post->getTitle()) . '<br>' . $this->statuses($post))
            ->renderAsHtml()
            ->onlyOnIndex()
        ;
        // The image the networks will show, under the address it links to - what is proofread is the text and the picture together
        $post = $this->getContext()?->getEntity()->getInstance();
        yield UrlField::new('url', t('label.social_post_url', [], 'social'))
            ->setDisabled()
            ->hideOnIndex()
            ->setHelp($post instanceof SocialPost ? $this->thumbnail($post, 300) : '')
            ->setFormTypeOption('help_html', true)
        ;
        yield CollectionField::new('targets', t('label.social_post_networks', [], 'social'))
            ->setEntryType(SocialPostTargetType::class)
            ->allowAdd(false)
            ->allowDelete(false)
            ->setEntryIsComplex()
            ->onlyOnForms()
        ;
    }

    // Sends every target of the post not out yet, then says which networks took it and which refused
    #[AdminRoute('/{entityId}/publish-post')]
    public function publishPost(AdminContext $context, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $post = $context->getEntity()->getInstance();
        if (!$post instanceof SocialPost || !$this->isCsrfTokenValid(self::PUBLISH_CSRF_TOKEN, $request->query->getString('token'))) {
            return $this->redirect($this->indexUrl());
        }

        $report = $this->socialPublisher->publish($post);
        null === $report ? $this->addFlash('warning', t('flash.social_post_publishing', [], 'social')) : $this->flashReport($report);

        return $this->redirect($this->indexUrl());
    }

    // Prepares the next content now, whatever the interval, and sends it at once where a network publishes automatically
    #[AdminRoute('/prepare-next-post')]
    public function prepareNextPost(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        if ($this->isCsrfTokenValid(self::PUBLISH_CSRF_TOKEN, $request->query->getString('token'))) {
            $this->flashReport($this->socialPublisher->prepareNext(true));
        }

        return $this->redirect($this->indexUrl());
    }

    // Asks for a page's address, then prepares a post of it from its Open Graph tags
    #[AdminRoute('/prepare-url-post')]
    public function prepareUrlPost(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $form = $this->createFormBuilder()
            ->add('url', UrlType::class, [
                'label' => t('label.social_post_url', [], 'social'),
                'default_protocol' => 'https',
                'constraints' => [new NotBlank(), new Url(requireTld: true)],
            ])
            ->getForm()
            ->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->flashReport($this->socialPublisher->prepareUrl((string) $form->get('url')->getData()));

                return $this->redirect($this->indexUrl());
            } catch (\Throwable $exception) {
                // The page did not answer, or said nothing: the editor stays on the form, the address still in it
                $this->addFlash('danger', $exception->getMessage());
            }
        }

        return $this->render('@c975LSocial/management/social_post_prepare_url.html.twig', ['form' => $form]);
    }

    // One message per network: published, waiting for review, or the network's own refusal
    /** @param array<string, array<string, mixed>> $report */
    private function flashReport(array $report): void
    {
        if ([] === $report) {
            $this->addFlash('warning', t('flash.social_post_nothing', [], 'social'));

            return;
        }

        foreach ($report as $network => $result) {
            $parameters = ['%network%' => ucfirst($network), '%error%' => (string) $result['message']];
            match ($result['status']) {
                SocialPostStatus::Published->value => $this->addFlash('success', t('flash.social_post_published', $parameters, 'social')),
                SocialPostStatus::Draft->value => $this->addFlash('info', t('flash.social_post_draft', $parameters, 'social')),
                default => $this->addFlash('danger', t('flash.social_post_failed', $parameters, 'social')),
            };
        }
    }

    // The image the post goes out with, at the given width - nothing for a post without one
    private function thumbnail(SocialPost $post, int $width): string
    {
        $imageUrl = $post->getImageUrl();

        return null === $imageUrl ? '' : sprintf('<img src="%s" width="%d" alt="" loading="lazy" class="d-block mb-1 rounded">', htmlspecialchars($imageUrl), $width);
    }

    // One badge per network, its name in it - the title and the status of every network being what the list is read for
    private function statuses(SocialPost $post): string
    {
        $badges = [];
        foreach ($post->getTargets() as $target) {
            $badges[] = sprintf(
                '<span class="badge badge-%s" title="%s">%s</span>',
                $target->getStatus()->badge(),
                htmlspecialchars($target->getStatus()->trans($this->translator)),
                htmlspecialchars(ucfirst($target->getNetwork())),
            );
        }

        return implode(' ', $badges);
    }

    private function publishUrl(SocialPost $post): string
    {
        return $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction('publishPost')
            ->setEntityId($post->getId())
            ->set('token', $this->csrfTokenManager->getToken(self::PUBLISH_CSRF_TOKEN)->getValue())
            ->generateUrl();
    }

    // A global action's url, carrying the same token as "Publish": preparing may post at once on an automatic network
    private function actionUrl(string $action): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setController(self::class)
            ->setAction($action)
            ->set('token', $this->csrfTokenManager->getToken(self::PUBLISH_CSRF_TOKEN)->getValue())
            ->generateUrl();
    }

    private function indexUrl(): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setController(self::class)
            ->setAction(Action::INDEX)
            ->generateUrl();
    }
}
