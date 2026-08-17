<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests;

use c975L\SocialBundle\c975LSocialBundle;
use c975L\SocialBundle\Service\ShareButtonsService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class c975LSocialBundleTest extends TestCase
{
    // Mirrors how Symfony's own kernel invokes it (BundleExtension::load() builds the ContainerConfigurator and calls loadExtension() for us), so this also validates that config/services.yaml itself parses and wires without error
    public function testLoadExtensionImportsServicesYaml(): void
    {
        $container = new ContainerBuilder();

        new c975LSocialBundle()->getContainerExtension()->load([], $container);

        $this->assertTrue($container->hasDefinition(ShareButtonsService::class));
    }

    // The glob in config/services.yaml registers everything under src/, so a new folder holding anything but services (DTOs, interfaces, entities) must be excluded or the consuming app's container stops compiling - this asserts every auto-registered class really is autowirable, which is what a real compile() would refuse
    public function testEveryAutoRegisteredClassIsAnAutowirableService(): void
    {
        $container = new ContainerBuilder();

        new c975LSocialBundle()->getContainerExtension()->load([], $container);

        foreach ($container->getDefinitions() as $id => $definition) {
            $class = $definition->getClass() ?? $id;

            // The excluded folders are registered as abstract placeholders, dropped at compile time - only the real services are checked here
            if (!str_starts_with($class, 'c975L\SocialBundle\\') || $definition->isAbstract()) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            $this->assertTrue($reflection->isInstantiable(), sprintf('"%s" is registered as a service but cannot be instantiated - exclude its folder in config/services.yaml.', $class));

            $arguments = $definition->getArguments();
            foreach ($reflection->getConstructor()?->getParameters() ?? [] as $position => $parameter) {
                if ($parameter->isDefaultValueAvailable() || isset($arguments[$position]) || isset($arguments['$' . $parameter->getName()])) {
                    continue;
                }

                $type = $parameter->getType();
                $this->assertTrue(
                    $type instanceof \ReflectionNamedType && !$type->isBuiltin(),
                    sprintf('"%s::$%s" cannot be autowired - "%s" is a data object, not a service, and its folder must be excluded in config/services.yaml.', $class, $parameter->getName(), $class)
                );
            }
        }
    }

    // Same real-kernel-hook approach as loadExtension() above (BundleExtension::prepend() calls prependExtension() for us) - asset_mapper needs this path so Twig's asset()/importmap can resolve "@c975l/social-bundle" to the bundle's own assets/ directory
    public function testPrependExtensionRegistersAssetMapperPathForBundleAssets(): void
    {
        $container = new ContainerBuilder();

        new c975LSocialBundle()->getContainerExtension()->prepend($container);

        $frameworkConfig = $container->getExtensionConfig('framework');
        $this->assertSame(
            \dirname(__DIR__) . '/src/../assets',
            array_key_first($frameworkConfig[0]['asset_mapper']['paths'])
        );
        $this->assertSame(
            '@c975l/social-bundle',
            $frameworkConfig[0]['asset_mapper']['paths'][\dirname(__DIR__) . '/src/../assets']
        );
    }

    public function testGetPathReturnsTheBundleRootDirectory(): void
    {
        $bundle = new c975LSocialBundle();

        $this->assertSame(\dirname(__DIR__), $bundle->getPath());
    }
}
