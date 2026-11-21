<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Mcp;

use c975L\ConfigBundle\Mcp\McpTool;
use c975L\ConfigBundle\Mcp\McpToolProviderInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Service\PublicWeb;
use c975L\SocialBundle\Service\SocialPlanner;
use c975L\SocialBundle\Service\SocialPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Server\Exception\ToolCallException;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;

// The posts of the social calendar on the site's MCP endpoint, for an account that edits them on the back office: listed, read, a draft written and its text corrected, approving and sending left to a human
class SocialMcpProvider implements McpToolProviderInterface
{
    // Days a list covers at most
    public const int MAX_DAYS = 92;

    // Posts a list gives at most
    public const int LIMIT = 100;

    public function __construct(
        private readonly SocialPostRepository $postRepository,
        private readonly SocialPublisher $publisher,
        private readonly SocialPlanner $planner,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConfigServiceInterface $configService,
        private readonly Security $security,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getMcpKey(): string
    {
        return 'social';
    }

    public function getMcpInstructions(): string
    {
        return 'Social posts are planned on the calendar of the site, one text per network. A post written by an agent is a draft: nothing goes out until a human approves it from the back office, and only a draft is corrected.';
    }

    public function getMcpTools(): iterable
    {
        yield new McpTool('social_list', 'Social posts', 'The posts planned between two days, drafts, approved and sent alike, with the state of each network, in their order.', $this->list(...));
        yield new McpTool('social_get', 'Social post', 'A post: its text, link, moment, series, and for each network its text, first comment, status, publication moment and refusal.', $this->get(...));
        yield new McpTool('social_create', 'Write a draft post', 'Writes a post as a draft at a moment to come, its text cut to each network; it is neither approved nor sent.', $this->create(...), true);
        yield new McpTool('social_edit', 'Correct a post', 'Changes the text of a draft written with a text of its own, every network\'s text written from it again; an approved post is left to the back office.', $this->edit(...), true);
    }

    // The posts planned between the two days, the last one included, in their order
    /**
     * @param ?string $from first day, YYYY-MM-DD, a week ago by default
     * @param ?string $to   last day, YYYY-MM-DD, a month after the first by default, 92 days after it at most
     *
     * @return list<array{id: ?int, title: string, planned: string, state: string, networks: array<string, string>}>
     */
    public function list(?string $from = null, ?string $to = null): array
    {
        $this->guard();
        $start = $this->day($from) ?? $this->clock->now()->setTime(0, 0)->modify('-7 days');
        $end = ($this->day($to) ?? $start->modify('+30 days'))->modify('+1 day');
        if ($end <= $start || $end > $start->modify('+' . self::MAX_DAYS . ' days')) {
            throw new ToolCallException(sprintf('The days run from the first to the last, %d days at most.', self::MAX_DAYS));
        }

        $posts = $this->postRepository->findPlannedBetween($start, $end);
        usort($posts, static fn (SocialPost $a, SocialPost $b): int => $a->getPlannedAt() <=> $b->getPlannedAt());

        return array_map($this->summary(...), \array_slice($posts, 0, self::LIMIT));
    }

    // A post with its text and each network's version
    /**
     * @param int $post id of the post
     *
     * @return array<string, mixed>
     */
    public function get(int $post): array
    {
        $found = $this->found($post);

        return [
            ...$this->summary($found),
            'text' => $found->getText(),
            'url' => '' === $found->getUrl() ? null : $found->getUrl(),
            'series' => $found->getSeries()?->getTitle(),
            'targets' => array_map($this->target(...), $found->getTargets()->getValues()),
        ];
    }

    // A draft written on the networks given or those ticked by default, at a moment to come brought to the quarter of an hour that no other post holds, never approved
    /**
     * @param string       $text     text of the post, cut to each network
     * @param ?string      $at       moment it would go out once approved, YYYY-MM-DD HH:MM in the site's time, the next quarter of an hour by default
     * @param ?string      $url      address the post links to
     * @param list<string> $networks networks it goes to, among the connected ones, those ticked by default when empty
     *
     * @return array{id: ?int, planned: string, networks: list<string>}
     */
    public function create(string $text, ?string $at = null, ?string $url = null, array $networks = []): array
    {
        $this->guard();
        if ('' === trim($text)) {
            throw new ToolCallException('The text is empty.');
        }

        $moment = $this->moment($at);
        // Two posts never go out at the same minute on a site, whatever their networks
        $held = $this->postRepository->findTakenAt([$moment]);
        if ([] !== $held) {
            throw new ToolCallException(sprintf('The moment %s is already held by "%s": choose another one.', $moment->format('Y-m-d H:i'), reset($held)));
        }
        $post = $this->publisher->createManual($moment)->setUrl($this->url($url));
        $post->setText($text);
        if ([] !== $networks) {
            $post->setNetworks($this->networks($networks));
        }
        $this->writeTargets($post);
        if ([] === $post->getNetworks()) {
            throw new ToolCallException('No connected network to write the post for.');
        }
        $this->entityManager->persist($post);
        $this->entityManager->flush();

        return ['id' => $post->getId(), 'planned' => $post->getPlannedAt()->format('Y-m-d H:i'), 'networks' => $post->getNetworks()];
    }

    // A draft's own text changed, every network's text written from it again - a post approved, refused, gone out anywhere or written from a content left to the back office
    /**
     * @param int    $post id of the post
     * @param string $text new text of the post, cut to each network
     *
     * @return array{id: ?int, state: string}
     */
    public function edit(int $post, string $text): array
    {
        $found = $this->found($post);
        if (!$found->hasOwnText() || 'draft' !== $found->getState() || $found->hasGoneOut()) {
            throw new ToolCallException(sprintf('The post %d is no draft with a text of its own.', $post));
        }
        if ('' === trim($text)) {
            throw new ToolCallException('The text is empty.');
        }

        $found->setText($text);
        $this->writeTargets($found);
        $this->entityManager->flush();

        return ['id' => $found->getId(), 'state' => $found->getState()];
    }

    // The calendar is the editor's business, as on the back office
    private function guard(): void
    {
        if (!$this->security->isGranted((string) $this->configService->get('site-role-editor'))) {
            throw new ToolCallException('This account may not work on the social posts.');
        }
    }

    // A post by its id
    private function found(int $id): SocialPost
    {
        $this->guard();
        $post = $this->postRepository->find($id);
        if (!$post instanceof SocialPost) {
            throw new ToolCallException(sprintf('No post %d.', $id));
        }

        return $post;
    }

    // The networks' texts written the way the post's screen writes them
    private function writeTargets(SocialPost $post): void
    {
        $this->publisher->rewriteTargets($post);
        $this->publisher->addTargets($post, $post->takeAddedNetworks());
    }

    // A post in a list, each network with its status
    /** @return array{id: ?int, title: string, planned: string, state: string, networks: array<string, string>} */
    private function summary(SocialPost $post): array
    {
        $networks = [];
        foreach ($post->getTargets() as $target) {
            $networks[$target->getNetwork()] = $target->getStatus()->value;
        }

        return [
            'id' => $post->getId(),
            'title' => $post->getTitle(),
            'planned' => $post->getPlannedAt()->format('Y-m-d H:i'),
            'state' => $post->getState(),
            'networks' => $networks,
        ];
    }

    // One network's version of a post
    /** @return array{network: string, status: string, text: string, comment: ?string, published: ?string, error: ?string} */
    private function target(SocialPostTarget $target): array
    {
        return [
            'network' => $target->getNetwork(),
            'status' => $target->getStatus()->value,
            'text' => $target->getText(),
            'comment' => $target->getComment(),
            'published' => $target->getPublishedAt()?->format('Y-m-d H:i'),
            'error' => $target->getError(),
        ];
    }

    // A day written YYYY-MM-DD, null when none is given - one PHP would roll over refused
    private function day(?string $day): ?\DateTimeImmutable
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

    // The moment asked brought to the quarter of an hour, the next quarter of an hour without one - a moment gone, or one PHP would roll over, refused
    private function moment(?string $at): \DateTimeImmutable
    {
        $now = $this->clock->now();
        if ('' === trim((string) $at)) {
            return $this->planner->nextQuarter($now);
        }

        $parsed = $this->parse('Y-m-d H:i', $at);
        if (null === $parsed) {
            throw new ToolCallException(sprintf('"%s" is not a moment written YYYY-MM-DD HH:MM.', $at));
        }
        $moment = $this->planner->round($parsed);
        if ($moment <= $now) {
            throw new ToolCallException(sprintf('The moment %s is gone.', $moment->format('Y-m-d H:i')));
        }

        return $moment;
    }

    // A date written in $format exactly, null for anything else
    private function parse(string $format, ?string $value): ?\DateTimeImmutable
    {
        $value = trim((string) $value);
        $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $value);

        return false !== $parsed && $parsed->format($format) === $value ? $parsed : null;
    }

    // An http(s) address a post may hold, empty when none is given
    private function url(?string $url): string
    {
        $url = trim((string) $url);
        if ('' !== $url && !PublicWeb::isAddress($url)) {
            throw new ToolCallException(sprintf('"%s" is not an http address.', $url));
        }

        return $url;
    }

    // The networks asked, each a connected one
    /**
     * @param list<string> $networks
     *
     * @return list<string>
     */
    private function networks(array $networks): array
    {
        $connected = $this->publisher->getConnectedNetworkNames();
        $unknown = array_diff($networks, $connected);
        if ([] !== $unknown) {
            throw new ToolCallException(sprintf('Not connected: %s. Connected: %s.', implode(', ', $unknown), implode(', ', $connected)));
        }

        return array_values(array_unique($networks));
    }
}
