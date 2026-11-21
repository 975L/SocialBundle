<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Templates;

use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Entity\SocialSlot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Forms;

// The template week rendered for real: each slot under its day with its pace, its series and its key, a clash flagged, the series off its slots offered to be laid again
class SocialSlotsTemplateTest extends TestCase
{
    // A slot of the week, its id given
    private function slot(int $id, string $label, int $weekday, string $time): SocialSlot
    {
        $slot = new SocialSlot()->setLabel($label)->setWeekday($weekday)->setTime($time);
        new \ReflectionProperty(SocialSlot::class, 'id')->setValue($slot, $id);

        return $slot;
    }

    public function testTheWeekShowsItsSlots(): void
    {
        $series = new SocialSeries('catalog', 1, 'days', 1, [], 'text', 'Text', [], ['bluesky']);
        new \ReflectionProperty(SocialSeries::class, 'id')->setValue($series, 4);
        $monday = $this->slot(1, 'item-1', 1, '20:30')->setSeries($series);
        $other = $this->slot(2, 'item-2', 1, '20:30')->setSlotKey('teaser-week');
        $rank = $this->slot(3, 'item-3', 5, '09:00')->setPace(SocialSlot::MONTH_RANK)->setRank(SocialSlot::LAST);
        $date = $this->slot(4, 'item-4', 1, '18:00')->setPace(SocialSlot::MONTH_DAY)->setMonthDay(12)->setWeekday(null);
        $form = Forms::createFormFactory()->createNamedBuilder('social_slot')->add('label', TextType::class)->getForm();

        $html = TemplateTestKernel::twig()->render('@c975LSocial/management/social_slots.html.twig', [
            'form' => $form->createView(),
            'slot' => $monday,
            'days' => [[$monday, $other], [], [], [], [$rank], [], []],
            'month_days' => [$date],
            'clashes' => [1 => [$other], 2 => [$monday]],
            'series' => [['series' => $series, 'off' => 2]],
            'token' => 'social_slots',
            'delete_url' => '/delete',
            'relay_url' => '/relay',
        ]);

        $this->assertStringContainsString('20:30 · item-1', $html);
        $this->assertStringContainsString('label.social_slot_clash', $html);
        $this->assertStringContainsString('teaser-week', $html);
        $this->assertStringContainsString('label.social_slot_rank_last', $html);
        $this->assertStringContainsString('label.social_slot_month_day_short', $html);
        $this->assertStringContainsString('label.social_slots_relay', $html);
        $this->assertStringContainsString('name="series" value="4"', $html);
        $this->assertStringContainsString('name="id" value="1"', $html);
    }
}
