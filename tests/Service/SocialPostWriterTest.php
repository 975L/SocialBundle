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
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Service\SocialPostWriter;
use c975L\UiBundle\Model\SocialContent;
use c975L\UiBundle\Service\AiUsageTracker;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SocialPostWriterTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $sent = null;

    private function writer(string $answer, bool $configured = true): SocialPostWriter
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key): ?string => match ($key) {
            'ui-ai-assistant-rephrase-provider' => $configured ? 'euria' : null,
            'ui-ai-assistant-rephrase-api-key' => 'key',
            'ui-ai-assistant-rephrase-base-uri' => 'https://api.example.org/v1',
            'ui-ai-assistant-rephrase-model' => 'model',
            'social-ai-guidelines' => 'Warm and simple, for parents of young children.',
            default => null,
        });

        $postRepository = $this->createStub(SocialPostRepository::class);
        $postRepository->method('findPublishedTexts')->willReturnCallback(static fn (string $network): array => 'bluesky' === $network ? ["Last night's story 🌙\nhttps://example.org/moon"] : []);

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($answer): MockResponse {
            $this->sent = json_decode((string) $options['body'], true);

            return new MockResponse((string) json_encode(['choices' => [['message' => ['content' => $answer]]]]));
        });

        return new SocialPostWriter($httpClient, $configService, new NullLogger(), $this->createStub(AiUsageTracker::class), $postRepository);
    }

    private function content(): SocialContent
    {
        return new SocialContent('42', 'The racing cars', 'https://example.org/racing-cars', variables: ['description' => 'Even racing cars can lose a wheel.']);
    }

    // What the model is told: the site's tone, the content, each network's rules and length, what the site already posted there, and the slot's own text
    public function testThePromptCarriesTheToneTheContentAndEachNetworksRules(): void
    {
        $this->writer('{}')->write($this->content(), ['bluesky' => 300, 'facebook' => 2000], ['slot' => '#bedtime']);

        $prompt = (string) ($this->sent['messages'][1]['content'] ?? '');
        foreach (['Warm and simple', 'The racing cars', 'https://example.org/racing-cars', 'Even racing cars can lose a wheel.', '#bedtime', 'bluesky, 300 characters at most', 'open question', "Last night's story", '"facebook": "the post"'] as $expected) {
            $this->assertStringContainsString($expected, $prompt);
        }
    }

    // Models wrap their JSON in a fence or a sentence; what is inside is what counts
    public function testTheTextsAreReadWhateverSurroundsThem(): void
    {
        $texts = $this->writer("Here you are:\n```json\n{\"bluesky\": \"Even racing cars lose a wheel 🏎️ https://example.org/racing-cars\", \"facebook\": \"Tonight, racing cars.\"}\n```")
            ->write($this->content(), ['bluesky' => 300, 'facebook' => 2000]);

        $this->assertSame(['bluesky' => 'Even racing cars lose a wheel 🏎️ https://example.org/racing-cars', 'facebook' => 'Tonight, racing cars.'], $texts);
    }

    // Too long, a text would be cut where the link stands; missing or unreadable, there is nothing to post - the template writes those
    public function testATextTooLongMissingOrUnreadableIsLeftToTheTemplate(): void
    {
        $this->assertSame(['facebook' => 'Short.'], $this->writer('{"bluesky": "' . str_repeat('a', 301) . '", "facebook": "Short."}')->write($this->content(), ['bluesky' => 300, 'facebook' => 2000, 'instagram' => 2200]));
        $this->assertSame([], $this->writer('Sorry, I cannot.')->write($this->content(), ['bluesky' => 300]));
    }

    // No key set: nothing is called, the template writes as before
    public function testWithoutAKeyNothingIsCalled(): void
    {
        $this->assertSame([], $this->writer('{"bluesky": "x"}', false)->write($this->content(), ['bluesky' => 300]));
        $this->assertNull($this->sent);
    }
}
