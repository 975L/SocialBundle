<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\ConfigBundle\Service\HtmlDocument;
use c975L\UiBundle\Model\SocialContent;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// Makes a content of any page from its Open Graph tags - the way to post what no bundle hands over as a source, a page of this site or of another one. Its title, description and image are what the page already shows when it is shared by hand
class SocialPageReader
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    // Throws when the page does not answer, or carries no title at all: a post with nothing to say is not worth preparing
    public function read(string $url): SocialContent
    {
        $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException(sprintf('The page %s answered %d.', $url, $response->getStatusCode()));
        }

        $tags = $this->tags($response->getContent());
        if ('' === $tags['title']) {
            throw new \RuntimeException(sprintf('The page %s carries no title.', $url));
        }

        return new SocialContent(
            sourceId: sha1($url),
            title: $tags['title'],
            url: $tags['og:url'] ?? $url,
            imageUrl: $tags['og:image'] ?? null,
            imageAlt: $tags['og:image:alt'] ?? null,
            variables: array_filter(['description' => $tags['og:description'] ?? $tags['description'] ?? '']),
        );
    }

    // The page's meta tags by name, its <title> standing as "title" when it has no og:title
    /** @return array<string, string> */
    private function tags(string $html): array
    {
        $xpath = HtmlDocument::xpath($html);
        $tags = [];
        foreach (HtmlDocument::elements($xpath, '//meta[@content][@property or @name]') as $meta) {
            $tags[strtolower($meta->getAttribute('property') ?: $meta->getAttribute('name'))] ??= trim($meta->getAttribute('content'));
        }
        $tags['title'] = $tags['og:title'] ?? trim((string) $xpath->query('//title')->item(0)?->textContent);

        return $tags;
    }
}
