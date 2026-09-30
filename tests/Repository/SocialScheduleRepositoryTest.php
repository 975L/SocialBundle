<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Repository;

use c975L\SocialBundle\Repository\SocialScheduleRepository;
use PHPUnit\Framework\TestCase;

class SocialScheduleRepositoryTest extends TestCase
{
    // A table the migration has not created yet reads as no slot, the interval run going on
    public function testAMissingTableHasNoEnabledSlot(): void
    {
        $repository = $this->getMockBuilder(SocialScheduleRepository::class)->disableOriginalConstructor()->onlyMethods(['findOneBy'])->getMock();
        $repository->expects($this->once())->method('findOneBy')->willThrowException(new \RuntimeException('Table "social_schedule" does not exist'));

        $this->assertFalse($repository->hasEnabled());
    }
}
