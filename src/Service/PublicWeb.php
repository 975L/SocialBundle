<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

// Reads an address an editor typed or a page named: over http or https, and on the public network alone, redirects included - the server must not be made to read what only it can reach
final class PublicWeb
{
    private const array SCHEMES = ['http', 'https'];

    // The length of SocialPost's $url and $imageUrl columns
    private const int MAX_LENGTH = 2048;

    // Whether a post may link to it: what SocialPost::$url's constraint and column take, nothing that would make a post impossible to save - whoever wrote the address, a third-party page or an editor's file
    public static function isAddress(string $url): bool
    {
        return '' !== $url
            && mb_strlen($url) <= self::MAX_LENGTH
            && 0 === \count(Validation::createValidator()->validate($url, new Url(protocols: self::SCHEMES, requireTld: false)));
    }

    // Throws a \RuntimeException on anything else, as on an address that does not answer
    public static function get(HttpClientInterface $httpClient, string $url): ResponseInterface
    {
        if (!\in_array(strtolower((string) parse_url($url, \PHP_URL_SCHEME)), self::SCHEMES, true)) {
            throw new \RuntimeException(sprintf('The address %s is not a web address.', $url));
        }

        return new NoPrivateNetworkHttpClient($httpClient)->request('GET', $url, ['timeout' => 30]);
    }
}
