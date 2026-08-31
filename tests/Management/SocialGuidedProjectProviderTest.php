<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Management\SocialGuidedProjectProvider;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

class SocialGuidedProjectProviderTest extends TestCase
{
    private function createAdminUrlGenerator(array &$controllers = []): AdminUrlGeneratorInterface
    {
        $generator = $this->createStub(AdminUrlGeneratorInterface::class);
        $generator->method('unsetAll')->willReturnSelf();
        $generator->method('setController')->willReturnCallback(function (string $controller) use ($generator, &$controllers) {
            $controllers[] = $controller;

            return $generator;
        });
        $generator->method('setAction')->willReturnSelf();
        $generator->method('generateUrl')->willReturn('/management/x');

        return $generator;
    }

    // Same stubbing as MenuProviderTest: the ConfigService answers "social-enable-share-buttons" with the given value. The two role configs are answered apart, the projects declaring them as their own role
    private function createProvider(bool $shareButtonsEnabled, array &$controllers = [], bool $reviewsEnabled = true, array $deniedRoles = []): SocialGuidedProjectProvider
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        // Each feature switch answered on its own: the two are independent, and a project dropped by the wrong one would still look right
        $configService->method('get')->willReturnCallback(static fn (string $slug): string => match ($slug) {
            'site-role-admin' => 'ROLE_ADMIN',
            'site-role-editor' => 'ROLE_EDITOR',
            'ui-enable-reviews' => $reviewsEnabled ? '1' : '0',
            default => $shareButtonsEnabled ? '1' : '0',
        });
        $configService->method('getBool')->willReturnCallback(static fn ($value) => '1' === $value);

        // Everything granted but what the case denies, so a parcours dropped for a missing role is dropped for that role alone
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn ($attribute): bool => !\in_array($attribute, $deniedRoles, true));

        return new SocialGuidedProjectProvider($configService, $this->createAdminUrlGenerator($controllers), $security);
    }

    // The 4000 block GuidedProjectProviderInterface reserves this bundle, at the step of 10 it states
    public function testGetGuidedProjectsContinuesTheOrderSequence(): void
    {
        $projects = $this->createProvider(true)->getGuidedProjects();

        $this->assertSame(['social-links', 'social-share-buttons', 'social-google-connect', 'social-google-reviews'], array_column($projects, 'slug'));
        $this->assertSame([4010, 4020, 4030, 4040], array_column($projects, 'order'));
    }

    // The share buttons screen isn't in the sidebar while the feature is off, so no parcours walks to it either
    public function testTheShareButtonsProjectIsDroppedWhileTheFeatureIsDisabled(): void
    {
        $projects = $this->createProvider(false)->getGuidedProjects();

        $this->assertSame(['social-links', 'social-google-connect', 'social-google-reviews'], array_column($projects, 'slug'));
    }

    // Reviews have a switch of their own, read exactly like the share buttons'
    public function testTheGoogleReviewsProjectIsDroppedWhileTheFeatureIsDisabled(): void
    {
        $controllers = [];
        $projects = $this->createProvider(true, $controllers, false)->getGuidedProjects();

        $this->assertSame(['social-links', 'social-share-buttons'], array_column($projects, 'slug'));
    }

    // The connection parcours walks three screens gated on three roles, none of them implying another: missing any one of them, it is not offered - where the reviews parcours, stopping at site-role-editor, still is
    public function testTheGoogleConnectProjectIsDroppedWhileARoleItWalksIsMissing(): void
    {
        $controllers = [];

        foreach (['ROLE_SUPER_ADMIN', 'ROLE_ADMIN', 'ROLE_EDITOR'] as $deniedRole) {
            $projects = $this->createProvider(true, $controllers, true, [$deniedRole])->getGuidedProjects();

            $this->assertNotContains('social-google-connect', array_column($projects, 'slug'), sprintf('The parcours is offered without "%s"', $deniedRole));
        }
    }

    // Denied the two roles its own key does not hold, the reviews parcours is untouched: it stops at site-role-editor
    public function testTheGoogleReviewsProjectSurvivesTheConnectionRoles(): void
    {
        $controllers = [];
        $projects = $this->createProvider(true, $controllers, true, ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN'])->getGuidedProjects();

        $this->assertSame(['social-links', 'social-share-buttons', 'social-google-reviews'], array_column($projects, 'slug'));
    }

    public function testEverySlugIsPrefixedWithTheBundleName(): void
    {
        foreach ($this->createProvider(true)->getGuidedProjects() as $project) {
            $this->assertStringStartsWith('social-', $project['slug'], 'A slug is unique across every bundle contributing projects');
        }
    }

    // Each project states the bar of the screens it walks, a role no other implies - too low, GuidedProjectBuilder offers a parcours ending on a 403. The Google connection is the one going above site-role-editor: its second step edits "restricted" configs, which ConfigCrudController hides below ROLE_SUPER_ADMIN
    public function testEveryProjectDemandsTheRoleItsScreensDo(): void
    {
        $roles = [];

        foreach ($this->createProvider(true)->getGuidedProjects() as $project) {
            $roles[$project['slug']] = $project['role'];
        }

        $this->assertSame([
            'social-links' => 'ROLE_EDITOR',
            'social-share-buttons' => 'ROLE_EDITOR',
            'social-google-connect' => 'ROLE_SUPER_ADMIN',
            'social-google-reviews' => 'ROLE_EDITOR',
        ], $roles);
    }

    public function testEveryProjectCarriesTheSocialTranslationDomainAndSteps(): void
    {
        foreach ($this->createProvider(true)->getGuidedProjects() as $project) {
            $this->assertSame('social', $project['translation_domain']);
            $this->assertNotEmpty($project['steps']);
        }
    }

    public function testNoStepSetsBothUrlAndHighlight(): void
    {
        foreach ($this->createProvider(true)->getGuidedProjects() as $project) {
            foreach ($project['steps'] as $index => $step) {
                $this->assertFalse(
                    isset($step['url']) && isset($step['highlight']),
                    sprintf('Step %d of "%s" sets both url and highlight', $index, $project['slug'])
                );
            }
        }
    }

    // Only the opening step leaves the screen, everything after it walking the one the user has been sent to
    public function testOnlyTheFirstStepOfEachProjectCarriesAnUrl(): void
    {
        foreach ($this->createProvider(true)->getGuidedProjects() as $project) {
            $steps = $project['steps'];

            $this->assertArrayHasKey('url', $steps[0], sprintf('Project "%s" does not open on a screen', $project['slug']));

            foreach (array_slice($steps, 1) as $index => $step) {
                $this->assertArrayNotHasKey('url', $step, sprintf('Step %d of "%s" leaves the screen again', $index + 1, $project['slug']));
            }
        }
    }

    public function testProjectsOpenOnACrudIndex(): void
    {
        $controllers = [];
        $this->createProvider(true, $controllers)->getGuidedProjects();

        // The two Google parcours are the exceptions, each opening on another bundle's screen: the keys the connection needs are configs, and the reviews are UiBundle's entity whatever platform brought them in
        $this->assertSame(
            ['SocialLinksCrudController', 'ShareButtonsSettingsCrudController', 'ConfigCrudController', 'ReviewCrudController'],
            array_map(static fn (string $fqcn): string => basename(str_replace('\\', '/', $fqcn)), $controllers)
        );
    }

    // EasyAdmin renders the form's save button as action-saveAndReturn, .action-save matching nothing and leaving the step highlighting an empty selection
    public function testEverySaveStepHighlightsTheEasyAdminSaveButton(): void
    {
        $saveSteps = [];

        foreach ($this->createProvider(true)->getGuidedProjects() as $project) {
            foreach ($project['steps'] as $step) {
                if (str_ends_with($step['label'], '_save')) {
                    $saveSteps[] = $step;
                }
            }
        }

        $this->assertCount(2, $saveSteps, 'Each project walks the user to the save button once');

        foreach ($saveSteps as $step) {
            $this->assertSame('.action-saveAndReturn', $step['highlight']);
        }
    }

    // A label or description with no translation reads as its own key in the panel
    public function testEveryLabelAndDescriptionIsTranslated(): void
    {
        $translated = $this->translatedKeys();

        foreach ($this->createProvider(true)->getGuidedProjects() as $project) {
            foreach ([$project, ...$project['steps']] as $item) {
                $this->assertContains($item['label'], $translated);
                if (isset($item['description'])) {
                    $this->assertContains($item['description'], $translated);
                }
            }
        }
    }

    private function translatedKeys(): array
    {
        $xliff = new \DOMDocument();
        $xliff->load(\dirname(__DIR__, 2) . '/translations/social.fr.xlf');

        $keys = [];
        foreach ($xliff->getElementsByTagName('source') as $source) {
            $keys[] = $source->textContent;
        }

        return $keys;
    }
}
