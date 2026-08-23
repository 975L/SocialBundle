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
    public function testTheReviewsSyncIsScheduledNightly(): void
    {
        $tasks = new SocialMaintenanceTaskProvider()->getMaintenanceTasks();

        $this->assertCount(1, $tasks);
        $this->assertSame('c975l:social:reviews:sync', $tasks[0]->command);
        $this->assertSame('# #(2-5) * * *', $tasks[0]->expression);
    }
}
