<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Management;

use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Test\ManagementTargetsTestCase;
use c975L\SocialBundle\Management\MenuProvider;
use c975L\SocialBundle\Management\SocialGuidedProjectProvider;
use c975L\UiBundle\Service\ConfigEditUrlResolver;
use Symfony\Bundle\SecurityBundle\Security;

// Every CRUD controller and route this bundle's management providers name, checked against what its controllers actually declare - see ConfigBundle's ManagementTargetsTestCase
class ManagementTargetsTest extends ManagementTargetsTestCase
{
    protected function managementProviders(): iterable
    {
        $configEditUrlResolver = new ConfigEditUrlResolver($this->adminUrlGenerator());

        return [
            new MenuProvider($this->configService(), $this->createStub(ConfigRepository::class), $configEditUrlResolver),
            // Switched off, the settings entry gives way to a link whose url is generated for ConfigCrudController, checked through the recorder
            new MenuProvider($this->configService(false), $this->createStub(ConfigRepository::class), $configEditUrlResolver),
            new SocialGuidedProjectProvider($this->configService(), $this->adminUrlGenerator(), $this->security()),
        ];
    }

    // Share buttons on by default: both providers hide their share buttons entry when they are off site-wide, and the screen it names would then never be checked
    private function configService(bool $enabled = true): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn($enabled ? 'true' : 'false');
        $configService->method('getBool')->willReturn($enabled);

        return $configService;
    }

    // Every role granted: the Google connection parcours names screens of its own, which would not be checked at all were it dropped here
    private function security(): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(true);

        return $security;
    }

    // This bundle's own controllers on top of ConfigBundle's, whose screens its entries point to as well - both directories, the sidebar's "Connecter Google" link naming a route declared outside Management/
    #[\Override]
    protected function controllerDirectories(): array
    {
        return [
            ...parent::controllerDirectories(),
            __DIR__ . '/../../src/Controller',
            __DIR__ . '/../../src/Controller/Management',
        ];
    }
}
