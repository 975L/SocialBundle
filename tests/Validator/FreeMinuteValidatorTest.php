<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Validator;

use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Service\SocialPlanner;
use c975L\SocialBundle\Validator\FreeMinute;
use c975L\SocialBundle\Validator\FreeMinuteValidator;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/** @extends ConstraintValidatorTestCase<FreeMinuteValidator> */
class FreeMinuteValidatorTest extends ConstraintValidatorTestCase
{
    // The minutes another post holds, with its title
    /** @var array<string, string> */
    private array $held = [];

    // The post ids the last lookup left out
    /** @var list<int> */
    private array $excepted = [];

    protected function createValidator(): ConstraintValidatorInterface
    {
        $repository = $this->createStub(SocialPostRepository::class);
        $repository->method('findTakenAt')->willReturnCallback(function (array $moments, array $exceptIds = []): array {
            $this->excepted = $exceptIds;

            return array_intersect_key($this->held, array_flip(array_map(static fn (\DateTimeImmutable $moment): string => $moment->format('Y-m-d H:i'), $moments)));
        });

        return new FreeMinuteValidator($repository, new SocialPlanner());
    }

    // A post to come at a minute another post holds is refused on its moment, itself left out of the lookup
    public function testAPostOnAHeldMinuteIsRefused(): void
    {
        $at = new \DateTimeImmutable('+2 days 09:00');
        $this->held = [$at->format('Y-m-d H:i') => 'item-2'];
        $post = new SocialPost(SocialPost::SOURCE_MANUAL, 'item-1', 'item-1', '', null, $at);
        new \ReflectionProperty(SocialPost::class, 'id')->setValue($post, 7);

        $this->validator->validateInContext($post, new FreeMinute(), $this->context);

        $this->buildViolation('label.social_post_minute_held')->setParameter('%title%', 'item-2')->atPath('property.path.plannedAt')->assertRaised();
        $this->assertSame([7], $this->excepted);
    }

    // A moment typed alone is brought to its quarter of an hour before being looked up
    public function testATypedMomentIsReadAtItsQuarter(): void
    {
        $at = new \DateTimeImmutable('+2 days 09:00');
        $this->held = [$at->format('Y-m-d H:i') => 'item-2'];

        $this->validator->validateInContext($at->modify('+5 minutes'), new FreeMinute(), $this->context);

        $this->buildViolation('label.social_post_minute_held')->setParameter('%title%', 'item-2')->assertRaised();
    }

    // A free minute, a moment gone by and a post gone out are left alone
    public function testWhatCannotGoOutAgainIsLeftAlone(): void
    {
        $at = new \DateTimeImmutable('+2 days 09:00');
        $this->validator->validateInContext($at, new FreeMinute(), $this->context);

        $this->held = [$at->format('Y-m-d H:i') => 'item-2', new \DateTimeImmutable('-1 day 09:00')->format('Y-m-d H:i') => 'item-3'];
        $this->validator->validateInContext(new \DateTimeImmutable('-1 day 09:00'), new FreeMinute(), $this->context);
        $post = new SocialPost(SocialPost::SOURCE_MANUAL, 'item-1', 'item-1', '', null, $at);
        new SocialPostTarget($post, 'bluesky', 'item-1')->markPublished('item-1');
        $this->validator->validateInContext($post, new FreeMinute(), $this->context);

        $this->assertNoViolation();
    }
}
