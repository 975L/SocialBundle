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
use c975L\ConfigBundle\Test\ManagementTargetsTestCase;
use c975L\SocialBundle\Management\MenuProvider;
use c975L\SocialBundle\Management\SocialGuidedProjectProvider;

// Every CRUD controller and route this bundle's management providers name, checked against what its controllers actually declare - see ConfigBundle's ManagementTargetsTestCase
class ManagementTargetsTest extends ManagementTargetsTestCase
{
    protected function managementProviders(): iterable
    {
        return [
            new MenuProvider($this->configService()),
            new SocialGuidedProjectProvider($this->configService(), $this->adminUrlGenerator(), $this->urlGenerator()),
        ];
    }

    // No switch is read any more, every entry and parcours being listed whatever its answer
    private function configService(): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('true');
        $configService->method('getBool')->willReturn(true);

        return $configService;
    }

    // This bundle's own controllers on top of ConfigBundle's, whose screens its entries point to as well - both directories, the guided parcours naming the OAuth routes declared outside Management/
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
