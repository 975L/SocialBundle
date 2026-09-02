<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Service\GoogleBusinessProfileSource;
use c975L\SocialBundle\Service\SocialDemoFixtureProvider;
use c975L\UiBundle\Entity\Review;
use c975L\UiBundle\Enum\ReviewStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

// The reviews a demo site shows as coming back from Google - UiBundle's entity, this bundle's rows
class SocialDemoFixtureProviderTest extends TestCase
{
    /** @return list<Review> */
    private function fixtures(): array
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id) => 'translated:' . $id);

        return iterator_to_array(new SocialDemoFixtureProvider($translator)->getDemoFixtures(), false);
    }

    // A row a sync could not match is a row it would write a second time on the next import
    public function testEveryReviewCarriesTheSourceAndAnIdentifierOfItsOwn(): void
    {
        $fixtures = $this->fixtures();

        $this->assertCount(2, $fixtures);
        foreach ($fixtures as $review) {
            $this->assertSame(GoogleBusinessProfileSource::NAME, $review->getSource());
            $this->assertNotNull($review->getExternalId());
            $this->assertNotNull($review->getSourceUrl());
        }

        $this->assertNotSame($fixtures[0]->getExternalId(), $fixtures[1]->getExternalId());
    }

    // An imported review is already public where it was written: the screen exists to answer it, not to let it through
    public function testTheyAreShownRatherThanWaitingToBe(): void
    {
        foreach ($this->fixtures() as $review) {
            $this->assertSame(ReviewStatus::Published, $review->getStatus());
            $this->assertTrue($review->isVerified());
        }
    }

    // The unanswered one is the row the guided project opens, and a demo where everything is answered has nothing to walk through
    public function testExactlyOneOfThemIsStillWaitingForAnAnswer(): void
    {
        $unanswered = array_filter($this->fixtures(), static fn (Review $review) => null === $review->getReplyComment());

        $this->assertCount(1, $unanswered);
    }

    // A demo is reloaded often, and a date taken from the clock would say something else in every take of the same recorded sequence
    public function testTheDatesAreFixedRatherThanTakenFromTheClock(): void
    {
        $this->assertSame(
            ['2026-01-16', '2026-02-22'],
            array_map(static fn (Review $review) => $review->getPublishedAt()->format('Y-m-d'), $this->fixtures()),
        );
    }
}
