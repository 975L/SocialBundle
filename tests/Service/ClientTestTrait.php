<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Service\ConfigValueWriter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// The seams a network's client is tested with - its configuration, the writer it stores tokens through and the HTTP client it calls - recording the requests in $calls and the writes in $written
trait ClientTestTrait
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: mixed}> */
    private array $calls = [];

    /** @var list<array<string, string|null>> */
    private array $written = [];

    // The configuration answering the given values, null for any other
    /** @param array<string, string> $config */
    private function configService(array $config): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key): ?string => $config[$key] ?? null);

        return $configService;
    }

    // The writer keeping what it is given in $written
    private function configValueWriter(): ConfigValueWriter
    {
        $writer = $this->createStub(ConfigValueWriter::class);
        $writer->method('write')->willReturnCallback(function (array $values): void {
            $this->written[] = $values;
        });

        return $writer;
    }

    // The responses given in turn, then the default body for every request beyond them
    /** @param list<MockResponse> $responses */
    private function httpClient(array $responses, string $default = '{}'): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use (&$responses, $default): MockResponse {
            $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? [], 'body' => $options['body'] ?? null];

            return array_shift($responses) ?? new MockResponse($default);
        });
    }
}
