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

    // Same stubbing as MenuProviderTest: the ConfigService answers "social-enable-share-buttons" with the given value. "site-role-editor" is answered apart, the projects declaring it as their own role
    private function createProvider(bool $shareButtonsEnabled, array &$controllers = []): SocialGuidedProjectProvider
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $slug): string => match ($slug) {
            'site-role-editor' => 'ROLE_EDITOR',
            default => $shareButtonsEnabled ? '1' : '0',
        });
        $configService->method('getBool')->willReturnCallback(static fn ($value) => '1' === $value);

        return new SocialGuidedProjectProvider($configService, $this->createAdminUrlGenerator($controllers));
    }

    // Continues the sequence after ConfigBundle (10-40), SiteBundle (50-80) and UiBundle (90-110), staying below GalleryBundle's own 140
    public function testGetGuidedProjectsContinuesTheOrderSequence(): void
    {
        $projects = $this->createProvider(true)->getGuidedProjects();

        $this->assertSame(['social-links', 'social-share-buttons', 'social-google-reviews'], array_column($projects, 'slug'));
        $this->assertSame([130, 135, 137], array_column($projects, 'order'));
    }

    // The share buttons screen isn't in the sidebar while the feature is off, so no parcours walks to it either
    public function testTheShareButtonsProjectIsDroppedWhileTheFeatureIsDisabled(): void
    {
        $projects = $this->createProvider(false)->getGuidedProjects();

        $this->assertSame(['social-links', 'social-google-reviews'], array_column($projects, 'slug'));
    }

    public function testEverySlugIsPrefixedWithTheBundleName(): void
    {
        foreach ($this->createProvider(true)->getGuidedProjects() as $project) {
            $this->assertStringStartsWith('social-', $project['slug'], 'A slug is unique across every bundle contributing projects');
        }
    }

    // Both screens gate their own INDEX by site-role-editor, a role the dashboard's own site-role-admin doesn't imply - without this, GuidedProjectBuilder would offer an admin lacking it a parcours ending on a 403
    public function testEveryProjectDemandsTheRoleItsScreenDoes(): void
    {
        foreach ($this->createProvider(true)->getGuidedProjects() as $project) {
            $this->assertSame('ROLE_EDITOR', $project['role'], sprintf('Project "%s" walks to an editor-only screen', $project['slug']));
        }
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

    public function testProjectsOpenOnTheirOwnCrudIndex(): void
    {
        $controllers = [];
        $this->createProvider(true, $controllers)->getGuidedProjects();

        // The reviews parcours is the exception: the two keys its connection needs are configs, so it opens on ConfigBundle's own screen rather than on one of this bundle's
        $this->assertSame(
            ['SocialLinksCrudController', 'ShareButtonsSettingsCrudController', 'ConfigCrudController'],
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
