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

    // The planned posts are sent every quarter of an hour, the calendar's grid, the medias of the posts gone out purged nightly, the series coming to their end announced nightly, the LinkedIn and Bluesky reminders checked nightly, the Pinterest, TikTok, LinkedIn and Instagram tokens renewed nightly, and the drafts due told each morning
    public function testThePlannedPostsAreSentEveryQuarterAndTheMediasPurgedNightly(): void
    {
        $tasks = new SocialMaintenanceTaskProvider()->getMaintenanceTasks();

        $this->assertCount(12, $tasks);
        $this->assertSame(['*/15 * * * *', 'c975l:social:publish'], [$tasks[1]->expression, $tasks[1]->command]);
        $this->assertSame(['# #(2-5) * * *', 'c975l:social:media:purge'], [$tasks[2]->expression, $tasks[2]->command]);
        $this->assertSame(['# #(2-5) * * *', 'c975l:social:series:ending'], [$tasks[3]->expression, $tasks[3]->command]);
        $this->assertSame(['# #(2-5) * * *', 'c975l:social:linkedin:reminder'], [$tasks[4]->expression, $tasks[4]->command]);
        $this->assertSame(['# #(2-5) * * *', 'c975l:social:bluesky:reminder'], [$tasks[5]->expression, $tasks[5]->command]);
        $this->assertSame(['# #(2-5) * * *', 'c975l:social:pinterest:refresh'], [$tasks[6]->expression, $tasks[6]->command]);
        $this->assertSame(['# #(2-5) * * *', 'c975l:social:tiktok:refresh'], [$tasks[7]->expression, $tasks[7]->command]);
        $this->assertSame(['# #(2-5) * * *', 'c975l:social:linkedin:refresh'], [$tasks[8]->expression, $tasks[8]->command]);
        $this->assertSame(['# #(2-5) * * *', 'c975l:social:instagram:refresh'], [$tasks[9]->expression, $tasks[9]->command]);
        $this->assertSame(['# */3 * * *', 'c975l:social:comments:check'], [$tasks[10]->expression, $tasks[10]->command]);
        $this->assertSame(['# 7 * * *', 'c975l:social:drafts:reminder'], [$tasks[11]->expression, $tasks[11]->command]);
    }
}
