<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Validator;

use Symfony\Component\Validator\Constraint;

// Two posts never go out at the same minute on a site, whatever their networks: on a post, its moment another post holds; on a moment typed alone, one any post holds (see FreeMinuteValidator)
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_PROPERTY)]
class FreeMinute extends Constraint
{
    public string $message = 'label.social_post_minute_held';

    #[\Override]
    public function getTargets(): string | array
    {
        return [self::CLASS_CONSTRAINT, self::PROPERTY_CONSTRAINT];
    }
}
