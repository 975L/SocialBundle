<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Scheduler;

use c975L\SocialBundle\Scheduler\SocialMaintenanceTaskProvider;
use PHPUnit\Framework\TestCase;

class SocialMaintenanceTaskProviderTest extends TestCase
{
    // The reviews are synced once a night, at a random time between 2 and 5
    public function testTheReviewsSyncIsScheduledNightly(): void
    {
        $tasks = new SocialMaintenanceTaskProvider()->getMaintenanceTasks();

        $this->assertSame('c975l:social:reviews:sync', $tasks[0]->command);
        $this->assertSame('# #(2-5) * * *', $tasks[0]->expression);
    }

    // The planned posts are sent every quarter of an hour, the calendar's grid, and the medias of the posts gone out purged nightly
    public function testThePlannedPostsAreSentEveryQuarterAndTheMediasPurgedNightly(): void
    {
        $tasks = new SocialMaintenanceTaskProvider()->getMaintenanceTasks();

        $this->assertCount(3, $tasks);
        $this->assertSame(['*/15 * * * *', 'c975l:social:publish'], [$tasks[1]->expression, $tasks[1]->command]);
        $this->assertSame(['# #(2-5) * * *', 'c975l:social:media:purge'], [$tasks[2]->expression, $tasks[2]->command]);
    }
}
