<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Templates;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Extra\Intl\IntlExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

// The "google_reviews" block as a visitor gets it: nothing at all without a review, one card alone, or UiBundle's slider markup around several - always saying where the reviews come from
class GoogleReviewsTemplateTest extends TestCase
{
    // The block adapter, its reviews handed over in place of GoogleReviewsExtension's query
    private function render(array $items, ?string $listingUrl = 'https://g.page/r/listing', string $title = 'Ils nous recommandent', int $duration = 5): string
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/templates', 'c975LSocial');
        $loader->addPath(\dirname(__DIR__, 2) . '/vendor/c975l/core-bundle/UiBundle/templates', 'c975LUi');

        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new IntlExtension());
        $twig->addExtension(new TemplateTestFunctions());
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));
        $twig->addFunction(new TwigFunction('social_google_reviews', static fn (): array => [
            'items' => $items,
            'count' => count($items),
            'average' => [] === $items ? null : 4.7,
            'listingUrl' => $listingUrl,
        ]));

        $block = new class {
            public int $id = 12;
        };

        return trim($twig->render('@c975LSocial/blocks/GoogleReviews.html.twig', [
            'block' => $block,
            'anchor_id' => '',
            'title' => $title,
            'duration' => $duration,
            'limit' => null,
        ]));
    }

    // What ReviewItem.html.twig reads, as GoogleReviewsExtension maps it - a Google review being never verified
    private function item(int $id): array
    {
        return [
            'id' => $id,
            'title' => 'Author ' . $id,
            'content' => 'Comment ' . $id,
            'url' => null,
            'imageUrl' => null,
            'rating' => 5,
            'publishedAt' => new \DateTimeImmutable('2026-10-01'),
            'replyComment' => null,
            'repliedAt' => null,
            'source' => 'google',
            'verified' => false,
        ];
    }

    // No title, no wrapper, nothing: a block with no review to show must leave no hole on the page
    public function testNoReviewRendersNothing(): void
    {
        $this->assertSame('', $this->render([]));
    }

    // Several reviews run on UiBundle's slider controller, one slide and one dot each, the duration typed in seconds and read in milliseconds
    public function testSeveralReviewsRunOnTheSliderController(): void
    {
        $html = $this->render([$this->item(1), $this->item(2), $this->item(3)]);

        $this->assertStringContainsString('data-controller="slider"', $html);
        $this->assertStringContainsString('data-slider-id="google-reviews-12"', $html);
        $this->assertStringContainsString('data-slider-duration="5000"', $html);
        $this->assertSame(3, substr_count($html, 'class="slider-item"'));
        $this->assertSame(3, substr_count($html, 'data-slide="'));
        $this->assertStringContainsString('slider-play-pause', $html);
        $this->assertStringContainsString('Comment 3', $html);
    }

    // The average heads the block, the mention and the link to the listing close it - and no card claims a verification Google does not make
    public function testTheBlockCarriesItsAverageItsSourceAndNoVerifiedBadge(): void
    {
        $html = $this->render([$this->item(1), $this->item(2)]);

        $this->assertStringContainsString('<h2 class="section-title">Ils nous recommandent</h2>', $html);
        $this->assertStringContainsString('google-reviews__average', $html);
        $this->assertStringContainsString('label.google_reviews_source', $html);
        $this->assertStringContainsString('href="https://g.page/r/listing"', $html);
        $this->assertStringNotContainsString('review-item-verified', $html);
    }

    // The link goes without a listing url, the mention never: reviews can be hidden one by one, so where they come from is always said
    public function testTheMentionStaysWithoutAListingUrl(): void
    {
        $html = $this->render([$this->item(1), $this->item(2)], null);

        $this->assertStringContainsString('label.google_reviews_source', $html);
        $this->assertStringNotContainsString('label.google_reviews_see_all', $html);
    }

    // One review needs no carousel, and a headingless block is no <section>
    public function testASingleReviewIsACardAloneUnderNoSection(): void
    {
        $html = $this->render([$this->item(1)], title: '');

        $this->assertStringNotContainsString('data-controller="slider"', $html);
        $this->assertStringContainsString('review-item', $html);
        $this->assertStringStartsWith('<div class="google-reviews"', $html);
    }

    // Set to 0 the carousel is turned by hand, so there is nothing to pause
    public function testAManualCarouselOffersNoPauseButton(): void
    {
        $html = $this->render([$this->item(1), $this->item(2)], duration: 0);

        $this->assertStringContainsString('data-slider-duration="0"', $html);
        $this->assertStringNotContainsString('slider-play-pause', $html);
    }
}
