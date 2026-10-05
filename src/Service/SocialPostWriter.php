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
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\UiBundle\Model\SocialContent;
use c975L\UiBundle\Service\AbstractAiProviderClient;
use c975L\UiBundle\Service\AiUsageTracker;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// Writes a post's text for each network with the site's own AI key - Donovan's writer one ("ui-ai-assistant-writer-*"), whatever provider it names, so a site has one key to keep and nothing here is tied to one vendor. What it is told: each network's own rules (written here, the same for every site), the site's tone ("social-ai-guidelines", the site's alone) and the last texts the site posted on each network, which it was approved with. Null whenever it cannot answer, the template then writing the post as before
class SocialPostWriter extends AbstractAiProviderClient
{
    // Spent under its own row, so a month of posts never reads as rephrasing
    public const string FEATURE = 'social_post';

    // How many texts already posted on a network it is shown, enough for a tone without paying for a history
    private const int EXAMPLES = 3;

    // What each network's audience expects - facts about the networks, not about a site. A network of a site's own gets its length alone
    private const array RULES = [
        'bluesky' => 'A short post. The link written out in the text. Zero to two hashtags.',
        'facebook' => 'Three to six short lines, ending with an open question to the reader. The link alone on the last line.',
        'instagram' => 'A caption of four to eight lines. Then the link written out, followed by "link in bio" in the language of the post. Then eight to twelve hashtags on a last line.',
        'linkedin' => 'A professional tone, three to six short lines, what the reader gains first. The link on the last line. Three hashtags at most.',
    ];

    public function __construct(
        HttpClientInterface $httpClient,
        private readonly ConfigServiceInterface $siteConfig,
        LoggerInterface $logger,
        AiUsageTracker $aiUsageTracker,
        private readonly SocialPostRepository $postRepository,
    ) {
        parent::__construct($httpClient, $siteConfig, $logger, $aiUsageTracker);
    }

    protected function configPrefix(): string
    {
        return 'ui-ai-assistant-writer';
    }

    protected function feature(): string
    {
        return self::FEATURE;
    }

    // One text per network, keyed by its name, from a single call - only the networks it wrote within their length, the others left to the template
    /**
     * @param array<string, int>    $maxLengths network => the most characters it takes
     * @param array<string, string> $variables  the caller's own values, "extra" a text to put as it is in every post
     *
     * @return array<string, string>
     */
    public function write(SocialContent $content, array $maxLengths, array $variables = []): array
    {
        if ([] === $maxLengths || !$this->isEnabled()) {
            return [];
        }

        $answer = $this->send($this->prompt($content, $maxLengths, $variables), 'You write the social network posts of a website. You follow the site\'s guidelines and each network\'s rules, and answer with a JSON object only.');

        return null === $answer ? [] : $this->texts($answer, $maxLengths);
    }

    // $count texts for a series of posts written from one instruction, each its own take on it, none longer than $maxLength - fewer, or none, when the AI cannot answer
    /** @return list<string> */
    public function variants(string $instruction, int $count, int $maxLength): array
    {
        if ($count < 1 || '' === trim($instruction) || !$this->isEnabled()) {
            return [];
        }

        $guidelines = trim((string) $this->siteConfig->get('social-ai-guidelines'));
        $prompt = ('' === $guidelines ? '' : "The site's guidelines:\n" . $guidelines . "\n\n")
            . "What the posts are about:\n" . trim($instruction) . "\n\n"
            . sprintf('Write %d different posts on it, each standing on its own, %d characters at most each, in the language of the instruction. Invent nothing the instruction does not say. Answer with this JSON array of strings only: ["the first post", ...]', $count, $maxLength);
        $answer = $this->send($prompt, 'You write the social network posts of a website. You follow the site\'s guidelines, and answer with a JSON array only.');

        $texts = [];
        foreach (null === $answer ? [] : $this->strings($answer) as $text) {
            if (mb_strlen($text) <= $maxLength && \count($texts) < $count) {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    // The non-empty strings of the array found in the answer, whatever a model puts around it
    /** @return list<string> */
    private function strings(string $answer): array
    {
        $start = strpos($answer, '[');
        $end = strrpos($answer, ']');
        $data = false === $start || false === $end ? null : json_decode(substr($answer, $start, $end - $start + 1), true);

        return \is_array($data) ? array_values(array_filter(array_map(static fn (mixed $text): string => \is_string($text) ? trim($text) : '', $data), static fn (string $text): bool => '' !== $text)) : [];
    }

    /**
     * @param array<string, int>    $maxLengths
     * @param array<string, string> $variables
     */
    private function prompt(SocialContent $content, array $maxLengths, array $variables): string
    {
        $prompt = '';
        $guidelines = trim((string) $this->siteConfig->get('social-ai-guidelines'));
        if ('' !== $guidelines) {
            $prompt .= "The site's guidelines:\n" . $guidelines . "\n\n";
        }

        $prompt .= "The content to post:\nTitle: " . $content->title . "\nLink: " . $content->url . "\n";
        foreach ($content->variables as $name => $value) {
            if ('' !== trim($value)) {
                $prompt .= ucfirst($name) . ': ' . $value . "\n";
            }
        }
        $extra = trim($variables['extra'] ?? '');
        if ('' !== $extra) {
            $prompt .= 'To include as it is in every post: ' . $extra . "\n";
        }

        $prompt .= "\nThe networks:\n";
        foreach ($maxLengths as $network => $maxLength) {
            $prompt .= sprintf("- %s, %d characters at most. %s\n", $network, $maxLength, self::RULES[$network] ?? '');
            foreach ($this->postRepository->findPublishedTexts($network, self::EXAMPLES) as $example) {
                $prompt .= "  A post the site published there, for its tone:\n  <<<\n  " . str_replace("\n", "\n  ", $example) . "\n  >>>\n";
            }
        }

        return $prompt . sprintf(
            "\nWrite in the language of the content. Keep the link exactly as given. Invent nothing the content does not say. Answer with this JSON object only: {%s}",
            implode(', ', array_map(static fn (string $network): string => sprintf('"%s": "the post"', $network), array_keys($maxLengths))),
        );
    }

    // The object found in the answer, whatever a model puts around it (a code fence, a sentence)
    /**
     * @param array<string, int> $maxLengths
     *
     * @return array<string, string>
     */
    private function texts(string $answer, array $maxLengths): array
    {
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');
        $data = false === $start || false === $end ? null : json_decode(substr($answer, $start, $end - $start + 1), true);
        if (!\is_array($data)) {
            return [];
        }

        $texts = [];
        foreach ($maxLengths as $network => $maxLength) {
            $text = \is_string($data[$network] ?? null) ? trim($data[$network]) : '';
            // Too long, it would be cut where the link stands: the template writes that one
            if ('' !== $text && mb_strlen($text) <= $maxLength) {
                $texts[$network] = $text;
            }
        }

        return $texts;
    }
}
