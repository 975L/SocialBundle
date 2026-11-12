<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Management;

use c975L\ConfigBundle\Entity\HealthCheckResult;

// The row a network's health check gives when the site does not use that network: skipped rather than nothing, so the last error or warning of a network since removed never stays on the dashboard
trait SkippedRowTrait
{
    // One skipped row for $url, saying why in $summary
    /** @return list<array{url: string, label: string, status: HealthCheckResult::STATUS_*, summary: string}> */
    private function skipped(string $url, string $label, string $summary): array
    {
        return [['url' => $url, 'label' => $label, 'status' => HealthCheckResult::STATUS_SKIPPED, 'summary' => $summary]];
    }
}
