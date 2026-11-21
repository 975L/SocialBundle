<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Validator;

use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Service\SocialPlanner;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

// The minute a post or a typed moment would go out at, refused when another post holds it - a post gone out or gone by left alone, nothing being sent again
class FreeMinuteValidator extends ConstraintValidator
{
    public function __construct(
        private readonly SocialPostRepository $postRepository,
        private readonly SocialPlanner $planner,
    ) {
    }

    // A post checked at its own moment, itself left out; a moment typed alone brought to the quarter of an hour it would go out at
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof FreeMinute) {
            throw new UnexpectedTypeException($constraint, FreeMinute::class);
        }

        [$moment, $exceptIds, $path] = match (true) {
            $value instanceof SocialPost && !$value->hasGoneOut() => [$value->getPlannedAt(), null === $value->getId() ? [] : [$value->getId()], 'plannedAt'],
            $value instanceof \DateTimeImmutable => [$this->planner->round($value), [], null],
            default => [null, [], null],
        };
        if (null === $moment || $moment <= new \DateTimeImmutable()) {
            return;
        }

        $held = $this->postRepository->findTakenAt([$moment], $exceptIds);
        if ([] !== $held) {
            $violation = $this->context->buildViolation($constraint->message)->setParameter('%title%', (string) reset($held))->setTranslationDomain('social');
            if (null !== $path) {
                $violation->atPath($path);
            }
            $violation->addViolation();
        }
    }
}
