<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle;

use c975L\ConfigBundle\DependencyInjection\Compiler\TaggedInterfacePass;
use c975L\SocialBundle\Contract\NetworkPublisherInterface;
use c975L\SocialBundle\Contract\ReviewsSourceInterface;
use c975L\SocialBundle\Namer\SocialMediaNamer;
use c975L\UiBundle\Contract\PickableMediaProviderInterface;
use c975L\UiBundle\Contract\SocialContentSourceInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class c975LSocialBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new TaggedInterfacePass(ReviewsSourceInterface::class, 'social.reviews_source'));
        $container->addCompilerPass(new TaggedInterfacePass(NetworkPublisherInterface::class, 'social.network_publisher'));
        $container->addCompilerPass(new TaggedInterfacePass(SocialContentSourceInterface::class, 'social.content_source'));
        $container->addCompilerPass(new TaggedInterfacePass(PickableMediaProviderInterface::class, 'social.pickable_media'));
    }

    public function loadExtension(array $config, ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void
    {
        $containerConfigurator->import('../config/services.yaml');
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // The medias uploaded for a post, named by SocialMediaNamer with their folder in the name - the nested storage UiBundle sets keeping it as the real path
        if ($builder->hasExtension('vich_uploader')) {
            $builder->prependExtensionConfig('vich_uploader', [
                'mappings' => [
                    'social_media' => [
                        'uri_prefix' => '',
                        'upload_destination' => '%kernel.project_dir%/public',
                        'namer' => SocialMediaNamer::class,
                        'inject_on_load' => false,
                        'delete_on_update' => true,
                        'delete_on_remove' => true,
                    ],
                ],
            ]);
        }

        $builder->prependExtensionConfig('framework', [
            'asset_mapper' => [
                'paths' => [
                    __DIR__ . '/../assets' => '@c975l/social-bundle',
                ],
            ],
        ]);
    }

    #[\Override]
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
