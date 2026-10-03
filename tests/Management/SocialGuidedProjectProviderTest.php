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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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

    // The screens opened by route rather than as a CRUD index, by their name
    private function createUrlGenerator(array &$routes = []): UrlGeneratorInterface
    {
        $generator = $this->createStub(UrlGeneratorInterface::class);
        $generator->method('generate')->willReturnCallback(static function (string $route) use (&$routes): string {
            $routes[] = $route;

            return '/' . $route;
        });

        return $generator;
    }

    // The two role configs are answered apart, the projects declaring them as their own role - every other config off, the feature switches included
    private function createProvider(array &$controllers = [], array &$routes = []): SocialGuidedProjectProvider
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $slug): string => match ($slug) {
            'site-role-admin' => 'ROLE_ADMIN',
            'site-role-editor' => 'ROLE_EDITOR',
            default => '0',
        });

        return new SocialGuidedProjectProvider($configService, $this->createAdminUrlGenerator($controllers), $this->createUrlGenerator($routes));
    }

    // The 4000 block GuidedProjectProviderInterface reserves this bundle, at the step of 10 it states - every project offered whatever the site's switches, the screens they walk being always in the sidebar
    public function testGetGuidedProjectsContinuesTheOrderSequence(): void
    {
        $projects = $this->createProvider()->getGuidedProjects();

        $this->assertSame(['social-links', 'social-share-buttons', 'social-google-connect', 'social-google-reviews', 'social-schedules', 'social-meta-connect', 'social-posts', 'social-bluesky-connect', 'social-linkedin-connect', 'social-calendar'], array_column($projects, 'slug'));
        $this->assertSame([4010, 4020, 4030, 4040, 4050, 4060, 4070, 4080, 4090, 4100], array_column($projects, 'order'));
    }

    public function testEverySlugIsPrefixedWithTheBundleName(): void
    {
        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            $this->assertStringStartsWith('social-', $project['slug'], 'A slug is unique across every bundle contributing projects');
        }
    }

    // Each project states the bar of the screens it walks - too low, GuidedProjectBuilder offers a parcours ending on a 403. The two connections list three roles, none implying another, all required by GuidedProjectBuilder
    public function testEveryProjectDemandsTheRoleItsScreensDo(): void
    {
        $roles = [];

        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            $roles[$project['slug']] = $project['role'];
        }

        $this->assertSame([
            'social-links' => 'ROLE_EDITOR',
            'social-share-buttons' => 'ROLE_EDITOR',
            'social-google-connect' => ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN', 'ROLE_EDITOR'],
            'social-google-reviews' => 'ROLE_EDITOR',
            'social-schedules' => 'ROLE_EDITOR',
            'social-meta-connect' => ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN', 'ROLE_EDITOR'],
            'social-posts' => 'ROLE_EDITOR',
            'social-bluesky-connect' => ['ROLE_ADMIN', 'ROLE_EDITOR'],
            'social-linkedin-connect' => ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN', 'ROLE_EDITOR'],
            'social-calendar' => 'ROLE_EDITOR',
        ], $roles);
    }

    public function testEveryProjectCarriesTheSocialTranslationDomainAndSteps(): void
    {
        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            $this->assertSame('social', $project['translation_domain']);
            $this->assertNotEmpty($project['steps']);
        }
    }

    public function testNoStepSetsBothUrlAndHighlight(): void
    {
        foreach ($this->createProvider()->getGuidedProjects() as $project) {
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
        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            $steps = $project['steps'];

            $this->assertArrayHasKey('url', $steps[0], sprintf('Project "%s" does not open on a screen', $project['slug']));

            foreach (array_slice($steps, 1) as $index => $step) {
                $this->assertArrayNotHasKey('url', $step, sprintf('Step %d of "%s" leaves the screen again', $index + 1, $project['slug']));
            }
        }
    }

    public function testProjectsOpenOnTheirScreen(): void
    {
        $controllers = [];
        $routes = [];
        $this->createProvider($controllers, $routes)->getGuidedProjects();

        // The connection parcours needing keys and the reviews one open on another bundle's screen: the keys are configs, and the reviews are UiBundle's entity whatever platform brought them in
        $this->assertSame(
            ['SocialLinksCrudController', 'ShareButtonsSettingsCrudController', 'ConfigCrudController', 'ReviewCrudController', 'SocialScheduleCrudController', 'ConfigCrudController', 'SocialPostCrudController', 'ConfigCrudController'],
            array_map(static fn (string $fqcn): string => basename(str_replace('\\', '/', $fqcn)), $controllers)
        );

        // Bluesky, with no key to paste, opens on the connections, and the calendar is a screen of its own: both routes, not CRUD indexes
        $this->assertSame(['management_social_connections', 'management_social_calendar'], $routes);
    }

    // EasyAdmin renders the form's save button as action-saveAndReturn, .action-save matching nothing and leaving the step highlighting an empty selection
    public function testEverySaveStepHighlightsTheEasyAdminSaveButton(): void
    {
        $saveSteps = [];

        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            foreach ($project['steps'] as $step) {
                if (str_ends_with($step['label'], '_save')) {
                    $saveSteps[] = $step;
                }
            }
        }

        $this->assertCount(4, $saveSteps, 'Each project walks the user to the save button once');

        foreach ($saveSteps as $step) {
            $this->assertSame('.action-saveAndReturn', $step['highlight']);
        }
    }

    // A label or description with no translation reads as its own key in the panel
    public function testEveryLabelAndDescriptionIsTranslated(): void
    {
        $translated = $this->translatedKeys();

        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            foreach ([$project, ...$project['steps']] as $item) {
                $this->assertContains($item['label'], $translated);
                if (isset($item['description'])) {
                    $this->assertContains($item['description'], $translated);
                }
            }
        }
    }

    // A narration with no translation is read aloud as its own key
    public function testEveryNarrationIsTranslated(): void
    {
        $translated = $this->translatedKeys('social_narration');

        foreach ($this->createProvider()->getGuidedProjects() as $project) {
            foreach ($project['steps'] as $step) {
                if (isset($step['narration'])) {
                    $this->assertContains($step['narration'], $translated);
                }
            }
        }
    }

    private function translatedKeys(string $domain = 'social'): array
    {
        $xliff = new \DOMDocument();
        $xliff->load(\dirname(__DIR__, 2) . '/translations/' . $domain . '.fr.xlf');

        $keys = [];
        foreach ($xliff->getElementsByTagName('source') as $source) {
            $keys[] = $source->textContent;
        }

        return $keys;
    }
}
