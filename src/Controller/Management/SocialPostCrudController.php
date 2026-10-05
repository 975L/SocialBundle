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
use c975L\SocialBundle\Form\SocialMediaType;
use c975L\SocialBundle\Form\SocialPostTargetType;
use c975L\SocialBundle\Form\SocialSeriesType;
use c975L\SocialBundle\Service\SocialMediaChecker;
use c975L\SocialBundle\Service\SocialMediaPicker;
use c975L\SocialBundle\Service\SocialPlanner;
use c975L\SocialBundle\Service\SocialPublisher;
use c975L\SocialBundle\Service\SocialSeriesGenerator;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

// Where the posts prepared for the networks are read, corrected and sent - each network's text on its own, "Publish" sending every one not out yet. Every post is planned for a moment, where the calendar shows it; approved, it goes out then. A post is written here ("New", or a double click on the calendar) or prepared from a page; deleting one is cancelling it
class SocialPostCrudController extends AbstractCrudController
{
    // The one token "Publish" and the two ways of preparing a post are checked against
    private const string PUBLISH_CSRF_TOKEN = 'social_post_publish';

    // The time a post written for a day of the calendar's month opens on
    private const int DEFAULT_HOUR = 9;

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly SocialPublisher $socialPublisher,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $entityManager,
        private readonly SocialPlanner $planner,
        private readonly SocialMediaChecker $mediaChecker,
        private readonly SocialSeriesGenerator $seriesGenerator,
        private readonly SocialMediaPicker $mediaPicker,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return SocialPost::class;
    }

    // Tells the index whether a network is connected, its notice walking to the connections screen otherwise
    #[\Override]
    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        $responseParameters->set('has_connected_network', $this->socialPublisher->hasConnectedNetwork());

        return $responseParameters;
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

        // Lets the post go out at its planned moment, rather than sending it now
        $approve = Action::new('approvePost', t('label.social_post_approve', [], 'social'), 'fa fa-calendar-check')
            ->linkToUrl(fn (SocialPost $post): string => $this->entityActionUrl('approvePost', $post))
            ->displayIf(static fn (SocialPost $post): bool => $post->isApprovable())
        ;
        $unapprove = Action::new('unapprovePost', t('label.social_post_unapprove', [], 'social'), 'fa fa-calendar-xmark')
            ->linkToUrl(fn (SocialPost $post): string => $this->entityActionUrl('unapprovePost', $post))
            ->displayIf(static fn (SocialPost $post): bool => $post->isApproved())
        ;

        // A series of drafts at a steady pace, read and approved afterwards
        $generateSeries = Action::new('generateSeries', t('label.social_series_generate', [], 'social'), 'fa fa-layer-group')
            ->linkToUrl(fn (): string => $this->actionUrl('generateSeries'))
            ->createAsGlobalAction()
        ;
        // The drafts checked on the list approved together, each going out at its own moment
        $approveSelection = Action::new('approveSelection', t('label.social_post_approve_selection', [], 'social'), 'fa fa-calendar-check')
            ->createAsBatchAction()
            ->linkToCrudAction('approveSelection')
        ;
        // The site's own pictures and videos, from the libraries other bundles offer - from the saved post, the form's changes not carried
        $pickMedia = Action::new('pickMedia', t('label.social_media_pick', [], 'social'), 'fa fa-images')
            ->linkToUrl(fn (SocialPost $post): string => $this->entityActionUrl('pickMedia', $post))
            ->displayIf(fn (): bool => $this->mediaPicker->hasLibraries())
        ;
        // Any page, of this site or another one, read from its Open Graph tags
        $prepareUrl = Action::new('prepareUrlPost', t('label.social_post_prepare_url', [], 'social'), 'fa fa-link')
            ->linkToUrl(fn (): string => $this->actionUrl('prepareUrlPost'))
            ->createAsGlobalAction()
        ;

        return $actions
            ->setPermission(Action::INDEX, $role)
            ->setPermission(Action::NEW, $role)
            ->setPermission(Action::EDIT, $role)
            ->setPermission(Action::DELETE, $role)
            ->setPermission('publishPost', $role)
            ->setPermission('approvePost', $role)
            ->setPermission('unapprovePost', $role)
            ->setPermission('prepareUrlPost', $role)
            ->setPermission('generateSeries', $role)
            ->setPermission('approveSelection', $role)
            ->setPermission('pickMedia', $role)
            ->add(Crud::PAGE_INDEX, $generateSeries)
            ->add(Crud::PAGE_INDEX, $approveSelection)
            ->add(Crud::PAGE_INDEX, $prepareUrl)
            ->add(Crud::PAGE_EDIT, $pickMedia)
            ->disable(Action::DETAIL)
            // From the list only: on the post's page, a link would send the text as saved, not as just corrected
            ->add(Crud::PAGE_INDEX, $approve)
            ->add(Crud::PAGE_INDEX, $unapprove)
            ->add(Crud::PAGE_INDEX, $publish)
            ->update(Crud::PAGE_INDEX, 'approvePost', fn (Action $action) => EasyAdminActionHelper::toIconOnly($action, $this->translator->trans('label.social_post_approve', [], 'social')))
            ->update(Crud::PAGE_INDEX, 'unapprovePost', fn (Action $action) => EasyAdminActionHelper::toIconOnly($action, $this->translator->trans('label.social_post_unapprove', [], 'social')))
            ->update(Crud::PAGE_INDEX, 'publishPost', fn (Action $action) => EasyAdminActionHelper::toIconOnly($action, $this->translator->trans('label.social_post_publish', [], 'social')))
        ;
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        $post = $this->getContext()?->getEntity()->getInstance();
        $manual = $post instanceof SocialPost && $post->isManual();

        yield DateTimeField::new('createdAt', t('label.social_post_created_at', [], 'social'))->setDisabled()->hideWhenCreating();
        // A post written here is named by its text, one prepared from a content by that content
        if (!$manual) {
            yield TextField::new('title', t('label.social_post_title', [], 'social'))->setDisabled()->onlyOnForms();
        }
        // The title with each network's status under it, what waits for a decision being why the list is opened. Anchored on the title rather than on the targets: a TextField refuses a collection before any formatValue() is called
        yield TextField::new('title', t('label.social_post_title', [], 'social'))
            ->formatValue(fn (mixed $value, SocialPost $post): string => $this->thumbnail($post, 80) . htmlspecialchars($post->getTitle()) . '<br>' . $this->statuses($post))
            ->renderAsHtml()
            ->onlyOnIndex()
        ;
        // The text every network's own is cut from, Donovan's marker putting the rephrase and the translation under it
        if ($manual) {
            yield TextareaField::new('text', t('label.social_post_text', [], 'social'))
                ->setRequired(true)
                ->setFormTypeOption('constraints', [new NotBlank()])
                ->setHelp(t('help.social_post_text', [], 'social'))
                ->setFormTypeOption('attr', ['rows' => 8, 'data-ai-rephrase' => true])
                ->onlyOnForms()
            ;
        }
        // The image the networks will show, under the address it links to - what is proofread is the text and the picture together. A post written here links where it says, or nowhere
        yield UrlField::new('url', t('label.social_post_url', [], 'social'))
            ->setDisabled(!$manual)
            ->setRequired(false)
            ->hideOnIndex()
            ->setHelp($post instanceof SocialPost ? $this->thumbnail($post, 300) : '')
            ->setFormTypeOption('help_html', true)
        ;
        // The moment it goes out once approved, to the quarter of an hour
        yield DateTimeField::new('plannedAt', t('label.social_post_planned_at', [], 'social'))
            ->setRequired(true)
            ->setHelp(t('help.social_post_planned_at', [], 'social'))
            ->setFormTypeOption('attr', ['step' => SocialPlanner::QUARTER])
        ;

        // Its own pictures and videos, in their order, replacing the content's picture - checked against each network's rules once saved
        yield CollectionField::new('medias', t('label.social_post_medias', [], 'social'))
            ->setEntryType(SocialMediaType::class)
            ->allowAdd()
            ->allowDelete()
            ->setEntryIsComplex()
            ->setFormTypeOption('by_reference', false)
            ->setHelp(t('help.social_post_medias', [], 'social'))
            ->onlyOnForms()
        ;
        // Written once the post is saved, from its text or its content
        yield CollectionField::new('targets', t('label.social_post_networks', [], 'social'))
            ->hideWhenCreating()
            ->setEntryType(SocialPostTargetType::class)
            ->allowAdd(false)
            ->allowDelete(false)
            ->setEntryIsComplex()
            ->onlyOnForms()
        ;
        // After the texts, so a network unticked drops its text once the texts were read in: every network this site may post on, one not connected greyed out unless the post already goes there
        yield ChoiceField::new('networks', t('label.social_post_send_on', [], 'social'))
            ->setChoices($this->networkChoices($post instanceof SocialPost ? $post : null))
            ->allowMultipleChoices()
            ->renderExpanded()
            ->setFormTypeOption('choice_attr', fn (string $network): array => $this->isOffered($network, $post instanceof SocialPost ? $post : null) ? [] : ['disabled' => 'disabled'])
            ->setFormTypeOption('constraints', [new Count(min: 1)])
            ->setHelp(t('help.social_post_send_on', [], 'social'))
            ->onlyOnForms()
        ;
    }

    // A post written here, planned at the moment the calendar was double-clicked on ("at"), the next quarter of an hour otherwise
    #[\Override]
    public function createEntity(string $entityFqcn): SocialPost
    {
        $at = $this->getContext()?->getRequest()->query->getString('at') ?? '';

        return $this->socialPublisher->createManual($this->moment($at));
    }

    // A new post gets its texts written before it is saved, from its own text
    #[\Override]
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof SocialPost) {
            $this->writeTargets($entityInstance);
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    // A network ticked on the screen gets its text written before the post is saved, and a text changed is written again on every network not out yet
    #[\Override]
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof SocialPost) {
            $this->writeTargets($entityInstance);
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    // Its texts written, then what its medias do not suit said - its approval being what refuses them
    private function writeTargets(SocialPost $post): void
    {
        $this->socialPublisher->rewriteTargets($post);
        $this->socialPublisher->addTargets($post, $post->takeAddedNetworks());
        $this->flashMediaProblems($post, 'warning');
    }

    // One message per network and problem, saying so; whether there was any
    private function flashMediaProblems(SocialPost $post, string $type): bool
    {
        $problems = $this->mediaChecker->check($post);
        foreach ($problems as $network => $messages) {
            foreach ($messages as $message) {
                $this->addFlash($type, ucfirst($network) . ' : ' . $message->trans($this->translator));
            }
        }

        return [] !== $problems;
    }

    // The calendar's moment: an ISO moment brought to the quarter of an hour, a day alone at DEFAULT_HOUR - the next quarter of an hour for anything else, or a moment gone already
    private function moment(string $at): \DateTimeImmutable
    {
        $next = $this->planner->nextQuarter(new \DateTimeImmutable());
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $at);
        try {
            $moment = false !== $day ? $day->setTime(self::DEFAULT_HOUR, 0) : ('' === $at ? $next : $this->planner->round(new \DateTimeImmutable($at)->setTimezone(new \DateTimeZone(date_default_timezone_get()))));
        } catch (\Exception) {
            return $next;
        }

        return $moment > new \DateTimeImmutable() ? $moment : $next;
    }

    // Every network a post may go to, labelled with whether the site is connected to it
    /** @return array<string, string> */
    private function networkChoices(?SocialPost $post): array
    {
        $connected = $this->socialPublisher->getConnectedNetworkNames();
        $choices = [];
        foreach (array_unique([...$this->socialPublisher->getNetworkNames(), ...($post?->getNetworks() ?? [])]) as $network) {
            $label = \in_array($network, $connected, true) ? ucfirst($network) : $this->translator->trans('label.network_not_connected', ['%network%' => ucfirst($network)], 'social');
            $choices[$label] = $network;
        }

        return $choices;
    }

    // A network not connected cannot be ticked, a text there having nothing to go out with - left as it is when the post already goes there
    private function isOffered(string $network, ?SocialPost $post): bool
    {
        return \in_array($network, $this->socialPublisher->getConnectedNetworkNames(), true) || \in_array($network, $post?->getNetworks() ?? [], true);
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

    // Lets the post go out at its planned moment
    #[AdminRoute('/{entityId}/approve-post')]
    public function approvePost(AdminContext $context, Request $request): RedirectResponse
    {
        return $this->changeApproval($context, $request, true);
    }

    // Takes the post back to a draft, at the same moment
    #[AdminRoute('/{entityId}/unapprove-post')]
    public function unapprovePost(AdminContext $context, Request $request): RedirectResponse
    {
        return $this->changeApproval($context, $request, false);
    }

    // Asks what the series is, then makes its drafts and shows them on the calendar, from its first moment
    #[AdminRoute('/generate-series')]
    public function generateSeries(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $form = $this->createForm(SocialSeriesType::class, null, [
            'networks' => $this->socialPublisher->getConnectedNetworkNames(),
            'sources' => $this->socialPublisher->getSourceChoices(),
            'start' => $this->planner->nextQuarter(new \DateTimeImmutable('tomorrow 09:00')),
        ])->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $moments = $this->seriesGenerator->moments($this->planner->round($data['start']), (int) $data['count'], (string) $data['frequency']);
            $posts = $this->seriesGenerator->generate($moments, (string) $data['mode'], $data['networks'], (string) $data['text'], $data['sources'] ?? [], $data['media']);

            if ([] === $posts) {
                $this->addFlash('warning', t('flash.social_post_nothing', [], 'social'));

                return $this->render('@c975LSocial/management/social_series.html.twig', ['form' => $form]);
            }

            $this->addFlash('success', t('flash.social_series_generated', ['%count%' => \count($posts)], 'social'));

            return $this->redirectToRoute(SocialCalendarController::ROUTE, ['date' => $moments[0]->format('Y-m-d')]);
        }

        return $this->render('@c975LSocial/management/social_series.html.twig', ['form' => $form]);
    }

    // The drafts checked approved, each going out at its own moment - one whose medias a network does not take left a draft, said why
    #[AdminRoute('/approve-selection')]
    public function approveSelection(BatchActionDto $batchActionDto): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        // The token EasyAdmin gives each batch action, as its own batchDelete checks it
        if (!$this->isCsrfTokenValid('ea-batch-action-' . $batchActionDto->getName() . '-' . $batchActionDto->getEntityFqcn(), $batchActionDto->getCsrfToken())) {
            return $this->redirect($this->indexUrl());
        }

        $approved = 0;
        foreach ($batchActionDto->getEntityIds() as $id) {
            $post = $this->entityManager->find(SocialPost::class, $id);
            if ($post instanceof SocialPost && $post->isApprovable() && !$this->flashMediaProblems($post, 'danger')) {
                $post->approve();
                ++$approved;
            }
        }
        $this->entityManager->flush();
        $this->addFlash('success', t('flash.social_posts_approved', ['%count%' => $approved], 'social'));

        return $this->redirect($this->indexUrl());
    }

    // Lists the libraries' medias, a search narrowing them, then adds those checked after the post's own and goes back to it
    #[AdminRoute('/{entityId}/pick-media')]
    public function pickMedia(AdminContext $context, Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $post = $context->getEntity()->getInstance();
        if (!$post instanceof SocialPost) {
            return $this->redirect($this->indexUrl());
        }

        $search = trim($request->request->getString('search'));
        if ($request->request->has('add') && $this->isCsrfTokenValid(self::PUBLISH_CSRF_TOKEN, $request->request->getString('token'))) {
            $paths = array_values(array_filter($request->request->all('paths'), is_string(...)));
            $added = $this->mediaPicker->attach($post, $search, $paths);
            $this->entityManager->flush();
            $this->addFlash('success', t('flash.social_media_picked', ['%count%' => $added], 'social'));
            $this->flashMediaProblems($post, 'warning');

            return $this->redirect($this->editUrl($post));
        }

        return $this->render('@c975LSocial/management/social_media_pick.html.twig', [
            'post' => $post,
            'search' => $search,
            'libraries' => $this->mediaPicker->libraries($search),
            'back_url' => $this->editUrl($post),
        ]);
    }

    // Asks for a page's address and a moment, then prepares a draft of it from its Open Graph tags, planned there
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
            ->add('plannedAt', DateTimeType::class, [
                'label' => t('label.social_post_planned_at', [], 'social'),
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'data' => $this->planner->nextQuarter(new \DateTimeImmutable()),
                'attr' => ['step' => SocialPlanner::QUARTER],
                'constraints' => [new NotBlank()],
            ])
            ->getForm()
            ->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->flashReport($this->socialPublisher->prepareUrl((string) $form->get('url')->getData(), $this->planner->round($form->get('plannedAt')->getData())));

                return $this->redirect($this->indexUrl());
            } catch (\Throwable $exception) {
                // The page did not answer, or said nothing: the editor stays on the form, the address still in it
                $this->addFlash('danger', $exception->getMessage());
            }
        }

        return $this->render('@c975LSocial/management/social_post_prepare_url.html.twig', ['form' => $form]);
    }

    // The two gestures on approval, checked against the token "Publish" carries: an approved post goes out under the site's name
    private function changeApproval(AdminContext $context, Request $request, bool $approve): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $post = $context->getEntity()->getInstance();
        if ($post instanceof SocialPost && $this->isCsrfTokenValid(self::PUBLISH_CSRF_TOKEN, $request->query->getString('token'))) {
            // A media a network does not take would only make it refuse the post at its moment
            if ($approve && $this->flashMediaProblems($post, 'danger')) {
                return $this->redirect($this->indexUrl());
            }

            $approve ? $post->approve() : $post->unapprove();
            $this->entityManager->flush();
            $this->addFlash('success', t($approve ? 'flash.social_post_approved' : 'flash.social_post_unapproved', ['%title%' => $post->getTitle()], 'social'));
        }

        return $this->redirect($this->indexUrl());
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
        $imageUrl = $post->getThumbnailUrl();

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
        return $this->entityActionUrl('publishPost', $post);
    }

    // An action on one post, carrying the token its route checks
    private function entityActionUrl(string $action, SocialPost $post): string
    {
        return $this->adminUrlGenerator
            ->setController(self::class)
            ->setAction($action)
            ->setEntityId($post->getId())
            ->set('token', $this->csrfTokenManager->getToken(self::PUBLISH_CSRF_TOKEN)->getValue())
            ->generateUrl();
    }

    // A global action's url, carrying the same token as "Publish"
    private function actionUrl(string $action): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setController(self::class)
            ->setAction($action)
            ->set('token', $this->csrfTokenManager->getToken(self::PUBLISH_CSRF_TOKEN)->getValue())
            ->generateUrl();
    }

    private function editUrl(SocialPost $post): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setController(self::class)
            ->setAction(Action::EDIT)
            ->setEntityId($post->getId())
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
