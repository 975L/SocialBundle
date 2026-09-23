<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

// Where one network's version of a post stands. No "scheduled" nor "cancelled": the hourly run is the schedule, and cancelling is deleting. Translatable so EasyAdmin offers the cases translated with no choice list to keep in step (same as UiBundle's ReviewStatus)
enum SocialPostStatus: string implements TranslatableInterface
{
    // Waiting for someone to read it and press "Publish", on a network set to review
    case Draft = 'draft';
    case Published = 'published';
    // The network refused it; it stays so until someone publishes it again from the screen, never retried on its own - a post refused for its content would be refused every hour
    case Failed = 'failed';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans('label.social_post_status_' . $this->value, [], 'social', $locale);
    }

    // The badge of a status as EasyAdmin hands it over: the case name, its value or the case itself (see UiBundle's ReviewStatus::badgeFor())
    public static function badgeFor(mixed $value): string
    {
        if ($value instanceof self) {
            return $value->badge();
        }

        $name = \is_scalar($value) ? (string) $value : '';
        foreach (self::cases() as $case) {
            if ($case->value === $name || $case->name === $name) {
                return $case->badge();
            }
        }

        return self::Draft->badge();
    }

    // What waits for a decision and what failed are the two that have to catch the eye
    public function badge(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Published => 'success',
            self::Failed => 'danger',
        };
    }
}
