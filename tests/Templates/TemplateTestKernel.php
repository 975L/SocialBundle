<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Templates;

use EasyCorp\Bundle\EasyAdminBundle\Twig\Component\Alert;
use EasyCorp\Bundle\EasyAdminBundle\Twig\Component\Badge;
use EasyCorp\Bundle\EasyAdminBundle\Twig\Component\Button;
use EasyCorp\Bundle\EasyAdminBundle\Twig\Component\Icon;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use Twig\Loader\ArrayLoader;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// Twig with TwigComponent and EasyAdmin's `ea:*` components wired as EasyAdmin wires them, so the bundle's templates render the HTML a back-office page gets - the dashboard aside, its admin context being none (FontAwesome icons, no prefix)
class TemplateTestKernel extends Kernel
{
    use MicroKernelTrait;

    // The three bundles the templates need, nothing of EasyAdmin's own boot
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new TwigBundle(), new TwigComponentBundle()];
    }

    // Out of the repository, where FrameworkBundle would otherwise write its config/reference.php
    public function getProjectDir(): string
    {
        return sys_get_temp_dir() . '/c975l-social-template-test';
    }

    // One cache per environment, under the project
    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/' . $this->environment;
    }

    // Next to the cache
    public function getLogDir(): string
    {
        return $this->getCacheDir() . '/log';
    }

    // The services and the configuration EasyAdmin prepends for its components
    protected function configureContainer(ContainerConfigurator $container): void
    {
        $easyAdmin = \dirname(__DIR__, 2) . '/vendor/easycorp/easyadmin-bundle/templates';

        $container->extension('framework', ['test' => true, 'secret' => 'test', 'http_method_override' => false, 'router' => ['enabled' => false], 'assets' => ['enabled' => false], 'csrf_protection' => false, 'translator' => ['enabled' => false]]);
        $container->extension('twig', [
            'strict_variables' => true,
            'paths' => [\dirname(__DIR__, 2) . '/templates' => 'c975LSocial', $easyAdmin => 'EasyAdmin'],
        ]);
        $container->extension('twig_component', [
            'anonymous_template_directory' => 'components/',
            'defaults' => ['EasyCorp\\Bundle\\EasyAdminBundle\\Twig\\Component\\' => ['template_directory' => '@EasyAdmin/components/', 'name_prefix' => 'ea']],
        ]);

        $services = $container->services();
        $services->set('test.admin_context_provider', TemplateTestAdminContextProvider::class);
        $services->set(Icon::class)->args([service('test.admin_context_provider')])->tag('twig.component');
        $services->set(Alert::class)->tag('twig.component');
        $services->set(Badge::class)->tag('twig.component');
        $services->set(Button::class)->args([service('translator')])->tag('twig.component');

        // The layout the pages extend reduced to its block, ahead of EasyAdmin's own templates
        $services->set('test.twig.layout_loader', ArrayLoader::class)->args([['@EasyAdmin/page/content.html.twig' => '{%% block main %%}{%% endblock %%}']])->tag('twig.loader', ['priority' => 10]);
        $services->set('test.twig.functions', TemplateTestFunctions::class)->tag('twig.extension');
    }
}
