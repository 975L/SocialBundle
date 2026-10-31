<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

// The configs a network's client reads its credentials and tokens from, through the ConfigServiceInterface it holds as $configService
trait ConfigValuesTrait
{
    // A config trimmed, empty when unset
    private function config(string $slug): string
    {
        return trim((string) $this->configService->get($slug));
    }

    // A config read as a date, null when empty or unreadable
    private function date(string $slug): ?\DateTimeImmutable
    {
        $value = $this->config($slug);

        try {
            return '' === $value ? null : new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
