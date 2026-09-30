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
use c975L\SocialBundle\Repository\SocialScheduleRepository;

// The command this bundle needs run on a cadence, declared here rather than added by hand to every site's MaintenanceSchedule - the readme called the sync "meant for cron" long before anything actually scheduled it
class SocialMaintenanceTaskProvider implements MaintenanceTaskProviderInterface
{
    public function __construct(
        private readonly SocialScheduleRepository $scheduleRepository,
    ) {
    }

    public function getMaintenanceTasks(): array
    {
        return [
            // The reviews, nightly: a platform's answer changes slowly, and the daily pass is what makes a new review, an edited one and a deleted one all show up on their own. Declared whatever the config says - a site with no source configured is stepped over by the command itself, and one that turns the reviews back on gets what came in meanwhile without having to schedule anything
            new MaintenanceTask('# #(2-5) * * *', 'c975l:social:reviews:sync'),
            // The scheduled posts, checked hourly: the command itself waits for the interval the site set, so changing it needs no new schedule - and stands aside while a slot is enabled
            new MaintenanceTask('# * * * *', 'c975l:social:publish'),
            ...$this->slotTasks(),
        ];
    }

    // One task per enabled publication slot, at its own time: read when the worker starts, which its hourly time limit restarts, so a slot saved now runs within the hour
    /** @return list<MaintenanceTask> */
    private function slotTasks(): array
    {
        // A site whose migration has not created the table yet must keep every other task running
        try {
            $slots = $this->scheduleRepository->findEnabled();
        } catch (\Throwable) {
            return [];
        }

        $tasks = [];
        foreach ($slots as $slot) {
            $time = $slot->getTime();
            if (null !== $time) {
                $tasks[] = new MaintenanceTask(sprintf('%d %d * * *', (int) $time->format('i'), (int) $time->format('G')), 'c975l:social:publish --slot=' . $slot->getId());
            }
        }

        return $tasks;
    }
}
