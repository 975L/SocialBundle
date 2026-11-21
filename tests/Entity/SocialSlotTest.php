<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Entity;

use c975L\SocialBundle\Entity\SocialSlot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

class SocialSlotTest extends TestCase
{
    // The field the pace's check flags, none when the slot holds what its pace needs
    private function missing(SocialSlot $slot): ?string
    {
        $path = null;
        $builder = $this->createStub(ConstraintViolationBuilderInterface::class);
        $builder->method('setTranslationDomain')->willReturnSelf();
        $builder->method('atPath')->willReturnCallback(static function (string $field) use (&$path, $builder): ConstraintViolationBuilderInterface {
            $path = $field;

            return $builder;
        });
        $context = $this->createStub(ExecutionContextInterface::class);
        $context->method('buildViolation')->willReturn($builder);

        $slot->validatePace($context);

        return $path;
    }

    /** @return iterable<string, array{SocialSlot, ?string}> */
    public static function paces(): iterable
    {
        yield 'every week on a day' => [new SocialSlot(), null];
        yield 'every week without a day' => [new SocialSlot()->setWeekday(null), 'weekday'];
        yield 'one week in two without its first week' => [new SocialSlot()->setPace(SocialSlot::FORTNIGHT), 'startsOn'];
        yield 'one week in two from a week' => [new SocialSlot()->setPace(SocialSlot::FORTNIGHT)->setStartsOn(new \DateTimeImmutable('2026-11-02')), null];
        yield 'a rank of the month without its rank' => [new SocialSlot()->setPace(SocialSlot::MONTH_RANK), 'rank'];
        yield 'the last day of the month' => [new SocialSlot()->setPace(SocialSlot::MONTH_RANK)->setRank(SocialSlot::LAST), null];
        yield 'a date of the month without its date' => [new SocialSlot()->setPace(SocialSlot::MONTH_DAY)->setWeekday(null), 'monthDay'];
        yield 'a date of the month' => [new SocialSlot()->setPace(SocialSlot::MONTH_DAY)->setWeekday(null)->setMonthDay(31), null];
    }

    // Each pace asks for what it is reckoned on, and only that
    #[DataProvider('paces')]
    public function testEachPaceNeedsWhatItIsReckonedOn(SocialSlot $slot, ?string $missing): void
    {
        $this->assertSame($missing, $this->missing($slot));
    }

    // The texts typed are trimmed, an empty key naming nothing, and the time read as an hour and a minute
    public function testTheTypedValuesAreTrimmed(): void
    {
        $slot = new SocialSlot()->setLabel(' item-1 ')->setSlotKey('  ')->setTime(' 21:45 ');

        $this->assertSame(['item-1', null, [21, 45]], [$slot->getLabel(), $slot->getSlotKey(), $slot->getHourMinute()]);
        $this->assertSame('item-1', (string) $slot->setSlotKey(' item-1 '));
        $this->assertSame('item-1', $slot->getSlotKey());
    }
}
