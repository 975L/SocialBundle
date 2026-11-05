<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

// The TrueType file of a theme's font, the only format GD draws with while the site serves WOFF2 alone. Google Fonts answers a client that is not a browser with a TTF: it is fetched once by family and kept under var/, nothing being added to the site's public files
class SocialFontProvider
{
    private const string DIRECTORY = 'var/social-fonts';
    private const string CSS_URL = 'https://fonts.googleapis.com/css2';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $projectDir,
    ) {
    }

    // The path of the family's TTF, null for a family Google Fonts does not know ("sans-serif", a font of the site's own), and throwing when Google Fonts does not answer
    public function path(string $family): ?string
    {
        $family = $this->googleName($family);
        $path = $this->projectDir . '/' . self::DIRECTORY . '/' . sha1($family) . '.ttf';
        if (is_file($path)) {
            return $path;
        }

        $url = $this->fontUrl($family);
        if (null === $url) {
            return null;
        }

        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o775, true);
        }
        file_put_contents($path, $this->httpClient->request('GET', $url, ['timeout' => 30])->getContent());

        return $path;
    }

    // A family as Google Fonts names it: without its quotes or the "webfont" of a converted file, each word capitalized as the CSS API requires
    private function googleName(string $family): string
    {
        $name = trim($family, " \t\n\r\0\x0B\"'");

        return ucwords((string) preg_replace('/\s+webfont$/i', '', $name));
    }

    // The address of the family's TTF read from its stylesheet, null when the API refuses the family, its 400, and throwing on any other failure
    private function fontUrl(string $family): ?string
    {
        $response = $this->httpClient->request('GET', self::CSS_URL, ['query' => ['family' => $family], 'timeout' => 30]);
        if (400 === $response->getStatusCode()) {
            return null;
        }

        return 1 === preg_match('~https://fonts\.gstatic\.com/[^\s)]+\.ttf~', $response->getContent(), $matches) ? $matches[0] : null;
    }
}
