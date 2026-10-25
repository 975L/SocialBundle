<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Templates;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

// The functions of the routing, CSRF, asset, UI and social bundles, answering in a shape the tests can read
final class TemplateTestFunctions extends AbstractExtension
{
    // Each one echoing what it was given
    public function getFunctions(): array
    {
        return [
            new TwigFunction('path', static fn (string $route, array $parameters = []): string => '/' . $route . ([] === $parameters ? '' : '?' . http_build_query($parameters))),
            new TwigFunction('csrf_token', static fn (string $id): string => 'token-' . $id),
            new TwigFunction('asset', static fn (string $path): string => '/' . ltrim($path, '/')),
            new TwigFunction('asset_exists', static fn (string $path): bool => true),
            new TwigFunction('social_link_icon', static fn (string $network): ?string => 'linkedin' === $network ? null : 'bundles/c975lsocial/icons/' . $network . '.svg'),
        ];
    }

    // UiBundle's own, read as it reads it
    public function getFilters(): array
    {
        return [
            new TwigFilter('to_bool', static fn (mixed $value): bool => !\in_array($value, [false, 'false', '0', 0, ''], true)),
        ];
    }
}
