<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Mcp;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Mcp\Server\Exception\ToolCallException;
use Symfony\Bundle\SecurityBundle\Security;

// What every social tool checks before answering: the account is an editor, as on the back office, and a day it is given is a real one
class SocialMcpAccess
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly Security $security,
    ) {
    }

    // The calendar and the template week are the editor's business, as on the back office
    public function guard(): void
    {
        if (!$this->security->isGranted((string) $this->configService->get('site-role-editor'))) {
            throw new ToolCallException('This account may not work on the social posts.');
        }
    }

    // A day written YYYY-MM-DD, null when none is given - one PHP would roll over refused
    public function day(?string $day): ?\DateTimeImmutable
    {
        if ('' === trim((string) $day)) {
            return null;
        }

        $parsed = $this->parse('Y-m-d', $day);
        if (null === $parsed) {
            throw new ToolCallException(sprintf('"%s" is not a day written YYYY-MM-DD.', $day));
        }

        return $parsed;
    }

    // A date written in $format exactly, null for anything else
    public function parse(string $format, ?string $value): ?\DateTimeImmutable
    {
        $value = trim((string) $value);
        $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $value);

        return false !== $parsed && $parsed->format($format) === $value ? $parsed : null;
    }
}
