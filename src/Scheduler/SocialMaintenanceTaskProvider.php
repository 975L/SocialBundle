<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Scheduler;

use c975L\ConfigBundle\Scheduler\MaintenanceTask;
use c975L\ConfigBundle\Scheduler\MaintenanceTaskProviderInterface;

// The command this bundle needs run on a cadence, declared here rather than added by hand to every site's MaintenanceSchedule - the readme called the sync "meant for cron" long before anything actually scheduled it
class SocialMaintenanceTaskProvider implements MaintenanceTaskProviderInterface
{
    public function getMaintenanceTasks(): array
    {
        return [
            // The reviews, nightly: a platform's answer changes slowly, and the daily pass is what makes a new review, an edited one and a deleted one all show up on their own. Declared whatever the config says - a site with no source configured is stepped over by the command itself, and one that turns the reviews back on gets what came in meanwhile without having to schedule anything
            new MaintenanceTask('# #(2-5) * * *', 'c975l:social:reviews:sync'),
        ];
    }
}
