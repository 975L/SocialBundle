<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\UiBundle\Model\SocialContent;

// Writes a post's text from the site's own template ("social-publish-template"), each "{name}" taking the content's value of that name
class SocialPostTextBuilder
{
    // What a site that wrote no template posts: the title, and the link that brings the reader back
    public const string DEFAULT_TEMPLATE = "{title}\n\n{url}";

    public function __construct(
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    // The text to post on a network taking at most $maxLength characters; a placeholder the content has no value for is dropped rather than posted as "{name}"
    public function build(SocialContent $content, int $maxLength): string
    {
        $template = trim((string) $this->configService->get('social-publish-template'));
        if ('' === $template) {
            $template = self::DEFAULT_TEMPLATE;
        }

        $replacements = [];
        foreach ([...$content->variables, 'title' => $content->title, 'url' => $content->url] as $name => $value) {
            $replacements['{' . $name . '}'] = $value;
        }

        $text = strtr($template, $replacements);
        $text = (string) preg_replace('/\{[a-z_]+\}/', '', $text);

        // An emptied placeholder on a line of its own would otherwise leave a gap of blank lines
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", $text));

        // Cut at the end, which loses the url of a template putting it last: a reviewer sees the cut and rewrites the text, an automatic network posts it as it is
        return mb_strlen($text) > $maxLength ? rtrim(mb_substr($text, 0, $maxLength - 1)) . '…' : $text;
    }
}
