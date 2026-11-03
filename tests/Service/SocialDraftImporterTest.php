<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Entity\SocialSeries;
use c975L\SocialBundle\Repository\SocialPostRepository;
use c975L\SocialBundle\Repository\SocialSeriesRepository;
use c975L\SocialBundle\Service\SocialDraftImporter;
use c975L\SocialBundle\Service\SocialPublisher;
use c975L\SocialBundle\Service\SocialSeriesGenerator;
use c975L\UiBundle\Model\SocialContent;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class SocialDraftImporterTest extends TestCase
{
    private string $dir;

    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/social-draft-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/public/medias', 0o777, true);
        file_put_contents($this->dir . '/card.png', 'png');
        file_put_contents($this->dir . '/public/medias/card.jpg', 'jpeg');
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), array_filter([$this->dir . '/card.png', $this->dir . '/public/medias/card.jpg'], is_file(...)));
        rmdir($this->dir . '/public/medias');
        rmdir($this->dir . '/public');
        rmdir($this->dir);
    }

    // LinkedIn taking a first comment and a reshare, Bluesky a story alone - both connected. $series is the series of the same title already there, $existing the post found in it by its key, $keyless the posts a former import made without a key at the draft's moment, $held the contents held by a post to come of another series, $keyed the series a keyed import made when it is not the latest one
    /**
     * @param list<SocialPost> $keyless
     * @param list<string>     $held
     */
    private function importer(?SocialSeries $series = null, ?SocialPost $existing = null, array $keyless = [], array $held = [], ?SocialSeries $keyed = null): SocialDraftImporter
    {
        $publisher = $this->createStub(SocialPublisher::class);
        $publisher->method('getConnectedNetworkNames')->willReturn(['bluesky', 'linkedin']);
        $publisher->method('getMaxLength')->willReturnCallback(static fn (string $network): int => 'bluesky' === $network ? 300 : 3000);
        $publisher->method('supportsComment')->willReturnCallback(static fn (string $network): bool => 'linkedin' === $network);
        $publisher->method('supportsReshare')->willReturnCallback(static fn (string $network): bool => 'linkedin' === $network);
        $publisher->method('supportsRepost')->willReturnCallback(static fn (string $network): bool => 'bluesky' === $network);
        $publisher->method('supportsStory')->willReturnCallback(static fn (string $network): bool => 'bluesky' === $network);
        $publisher->method('findContent')->willReturnCallback(static fn (string $type, string $id): ?SocialContent => 'book' === $type && \in_array($id, ['12', '13'], true) ? new SocialContent($id, 'Le Cerf-volant', 'https://example.org/histoire/cerf-volant', imageUrl: 'https://example.org/cover.jpg') : null);
        $publisher->method('isHeldOutside')->willReturnCallback(static fn (string $type, string $id, ?SocialSeries $own): bool => null === $own && \in_array($type . ':' . $id, $held, true));
        $publisher->method('createManual')->willReturnCallback(static function (\DateTimeImmutable $at): SocialPost {
            $post = new SocialPost(SocialPost::SOURCE_MANUAL, 'id', '', '', null, $at);
            new SocialPostTarget($post, 'bluesky', '');
            new SocialPostTarget($post, 'linkedin', '');

            return $post;
        });

        $generator = $this->createStub(SocialSeriesGenerator::class);
        $generator->method('store')->willReturn(['medias/card.jpg', 'image/jpeg', 1200, 1200, null]);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        $seriesRepository = $this->createStub(SocialSeriesRepository::class);
        $seriesRepository->method('findOneBy')->willReturn($series);
        $seriesRepository->method('findLastKeyedImport')->willReturn($keyed);
        $postRepository = $this->createStub(SocialPostRepository::class);
        $postRepository->method('findBy')->willReturnCallback(static fn (array $criteria): array => $criteria['series'] === ($keyed ?? $series) ? array_values(array_filter([$existing, ...$keyless])) : []);

        return new SocialDraftImporter($publisher, $generator, $entityManager, $postRepository, $seriesRepository, $this->dir);
    }

    // Every mistake of the file listed at once, and no draft while there is one
    public function testAWrongFileListsEveryMistake(): void
    {
        $read = $this->importer()->read(['posts' => [['at' => 'never', 'networks' => ['youtube'], 'text' => '', 'media' => 'missing.png']]], $this->dir);

        $this->assertCount(5, $read['errors']);
        $this->assertSame([], $read['drafts']);
    }

    // A moment gone, relative or rolled over is refused, a draft never placed where it cannot be approved
    public function testAMomentNotWrittenAsTheHelpSaysOrGoneIsRefused(): void
    {
        foreach (['2020-01-06 09:30' => 'in the past', 'monday' => 'is not a date', '2026-13-45 09:30' => 'is not a date'] as $at => $error) {
            $read = $this->importer()->read(['title' => 'T', 'posts' => [['at' => $at, 'networks' => ['linkedin'], 'text' => 'One idea']]], $this->dir);

            $this->assertStringContainsString($error, implode("\n", $read['errors']), $at);
        }
    }

    // Written as they are on the post, whose link the networks and the shared calendar's feed carry: nothing but a web address, and no post made while one is wrong
    public function testAUrlThatIsNoWebAddressRefusesTheFile(): void
    {
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 09:30';
        foreach (['javascript:alert(1)', 'data:text/html,x', 'mailto:a@example.org', '/a/path', 'https://'] as $url) {
            $read = $this->importer()->read(['title' => 'T', 'posts' => [['at' => $at, 'networks' => ['linkedin'], 'text' => 'One idea', 'url' => $url]]], $this->dir);

            $this->assertSame(['Post 1: "url" is not an http or https address.'], $read['errors'], $url);
            $this->assertSame([], $read['drafts'], $url);
        }
    }

    public function testAWebAddressPasses(): void
    {
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 09:30';
        $read = $this->importer()->read(['title' => 'T', 'posts' => [['at' => $at, 'networks' => ['linkedin'], 'text' => 'One idea', 'url' => 'https://example.org/page']]], $this->dir);

        $this->assertSame([], $read['errors']);
        $this->assertSame('https://example.org/page', $read['drafts'][0]['url']);
    }

    // The drafts make one imported series, each on its own networks alone, with its text, its picture and its first comment
    public function testTheDraftsMakeOneImportedSeries(): void
    {
        $importer = $this->importer();
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 09:30';
        $read = $importer->read(['title' => 'Bundles - November', 'posts' => [['at' => $at, 'networks' => ['linkedin'], 'text' => 'One idea', 'comment' => 'Read more: https://example.org', 'media' => 'card.png']]], $this->dir);
        $this->assertSame([], $read['errors']);

        $posts = $importer->import('Bundles - November', $read['drafts'])['created'];

        $this->assertInstanceOf(SocialSeries::class, $this->persisted[0]);
        $this->assertSame(SocialSeriesGenerator::MODE_IMPORT, $this->persisted[0]->getMode());
        $this->assertCount(1, $posts);
        $this->assertSame($this->persisted[0], $posts[0]->getSeries());
        $this->assertSame('One idea', $posts[0]->getText());
        $this->assertSame($at, $posts[0]->getPlannedAt()->format('Y-m-d H:i'));
        $this->assertCount(1, $posts[0]->getMedias());
        $comments = [];
        foreach ($posts[0]->getTargets() as $target) {
            $comments[$target->getNetwork()] = $target->getComment();
        }
        $this->assertSame(['linkedin' => 'Read more: https://example.org'], $comments);
        $this->assertFileExists($this->dir . '/card.png');
    }

    // A reshare and a story kept only on the networks offering them, never one that could only fail
    public function testReshareAndStoryAreKeptWhereTheNetworkOffersThem(): void
    {
        $importer = $this->importer();
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 09:30';
        $read = $importer->read(['title' => 'T', 'posts' => [['at' => $at, 'networks' => ['bluesky', 'linkedin'], 'text' => 'One idea', 'reshare' => ' Shared ', 'story' => true]]], $this->dir);
        $this->assertSame([], $read['errors']);

        $targets = [];
        foreach ($importer->import('T', $read['drafts'])['created'][0]->getTargets() as $target) {
            $targets[$target->getNetwork()] = [$target->getReshare(), $target->isStory()];
        }

        $this->assertSame(['bluesky' => [null, true], 'linkedin' => ['Shared', false]], $targets);
    }

    // A draft without a repost hashes as it did before reposting came to be: a file imported earlier stays unchanged, its posts approved
    public function testADraftWithoutARepostKeepsTheHashOfFormerImports(): void
    {
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 12:00';
        $importer = $this->importer();
        $read = $importer->read(['title' => 'T', 'posts' => [['at' => $at, 'networks' => ['bluesky'], 'text' => 'Text', 'key' => 'k']]], $this->dir);

        $post = $importer->import('T', $read['drafts'])['created'][0];

        $this->assertSame(hash('sha256', (string) json_encode(['at' => $at, 'networks' => ['bluesky'], 'text' => 'Text', 'comment' => null, 'reshare' => null, 'story' => false, 'url' => null, 'media' => null, 'key' => 'k', 'source' => null])), $post->getImportHash());
    }

    // A network's own text goes on its target, the others cut from the post's
    public function testANetworkTakesItsOwnText(): void
    {
        $importer = $this->importer();
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 12:00';
        $read = $importer->read(['title' => 'T', 'posts' => [['at' => $at, 'networks' => ['bluesky', 'linkedin'], 'text' => 'Long text', 'texts' => ['bluesky' => ' Short text ']]]], $this->dir);
        $this->assertSame([], $read['errors']);

        $texts = [];
        foreach ($importer->import('T', $read['drafts'])['created'][0]->getTargets() as $target) {
            $texts[$target->getNetwork()] = $target->getText();
        }

        $this->assertSame('Short text', $texts['bluesky']);
        $this->assertNotSame('Short text', $texts['linkedin']);
    }

    // A network the draft does not go to, a text empty or too long: told before anything is written
    public function testAWrongOwnTextIsRefused(): void
    {
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 12:00';
        $errors = $this->importer()->read(['title' => 'T', 'posts' => [
            ['at' => $at, 'networks' => ['linkedin'], 'text' => 'A', 'texts' => ['bluesky' => 'B']],
            ['at' => $at, 'networks' => ['bluesky'], 'text' => 'A', 'texts' => ['bluesky' => ' ']],
            ['at' => $at, 'networks' => ['bluesky'], 'text' => 'A', 'texts' => ['bluesky' => str_repeat('b', 301)]],
        ]], $this->dir)['errors'];

        $this->assertStringContainsString('not among its "networks"', $errors[0]);
        $this->assertStringContainsString('is empty', $errors[1]);
        $this->assertStringContainsString('longer than its 300', $errors[2]);
    }

    // A changed own text updates the post, the same one leaving it as it is
    public function testAnOwnTextIsPartOfTheHash(): void
    {
        $importer = $this->importer();
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 12:00';
        $draft = ['key' => 'a', 'at' => $at, 'networks' => ['bluesky'], 'text' => 'A', 'texts' => ['bluesky' => 'B']];
        $post = $importer->import('T', $importer->read(['title' => 'T', 'posts' => [$draft]], $this->dir)['drafts'])['created'][0];

        $again = $this->importer($this->importedSeries(), $post);
        $this->assertSame(1, $again->import('T', $again->read(['title' => 'T', 'posts' => [$draft]], $this->dir)['drafts'])['unchanged']);

        $draft['texts']['bluesky'] = 'C';
        $this->assertCount(1, $again->import('T', $again->read(['title' => 'T', 'posts' => [$draft]], $this->dir)['drafts'])['updated']);
        $this->assertSame('C', $post->getTargets()->first()->getText());
    }

    // Own texts in another order are the same ones, an unchanged file keeping its posts approved
    public function testTheOrderOfOwnTextsLeavesThePostUnchanged(): void
    {
        $importer = $this->importer();
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 12:00';
        $draft = ['key' => 'a', 'at' => $at, 'networks' => ['bluesky', 'linkedin'], 'text' => 'A', 'texts' => ['bluesky' => 'B', 'linkedin' => 'C']];
        $post = $importer->import('T', $importer->read(['title' => 'T', 'posts' => [$draft]], $this->dir)['drafts'])['created'][0];

        $draft['texts'] = ['linkedin' => 'C', 'bluesky' => 'B'];
        $again = $this->importer($this->importedSeries(), $post);
        $this->assertSame(1, $again->import('T', $again->read(['title' => 'T', 'posts' => [$draft]], $this->dir)['drafts'])['unchanged']);
    }

    // "texts" written as anything but an object of texts is told too, never dropped in silence
    public function testOwnTextsNotWrittenAsAnObjectAreRefused(): void
    {
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 12:00';
        $errors = $this->importer()->read(['title' => 'T', 'posts' => [
            ['at' => $at, 'networks' => ['bluesky'], 'text' => 'A', 'texts' => 'B'],
            ['at' => $at, 'networks' => ['bluesky'], 'text' => 'A', 'texts' => ['bluesky' => ['B']]],
        ]], $this->dir)['errors'];

        $this->assertStringContainsString('not among its "networks"', $errors[0]);
        $this->assertStringContainsString('is empty', $errors[1]);
    }

    // A repost is a draft like any other, its account kept on the target able to send it
    public function testARepostIsKeptOnItsTarget(): void
    {
        $importer = $this->importer();
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 12:00';
        $read = $importer->read(['title' => 'T', 'posts' => [['at' => $at, 'networks' => ['bluesky'], 'text' => 'Repost of A', 'url' => 'https://example.org/a', 'repost' => 'other.bsky.social']]], $this->dir);
        $this->assertSame([], $read['errors']);

        $target = $importer->import('T', $read['drafts'])['created'][0]->getTargets()->first();

        $this->assertSame('other.bsky.social', $target->getRepost());
    }

    // No link to find the post by, or a network unable to repost: told before anything is written
    public function testARepostWithoutLinkOrOnAnotherNetworkIsRefused(): void
    {
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 12:00';
        $read = $this->importer()->read(['title' => 'T', 'posts' => [['at' => $at, 'networks' => ['linkedin'], 'text' => 'Repost', 'repost' => 'other.bsky.social']]], $this->dir);

        $this->assertSame(['Post 1: "repost" needs the "url" its post carries.', 'Post 1: linkedin cannot repost, connect it again if it should.'], $read['errors']);
    }

    // Two posts of a file never share a key, which would make one of them update the other
    public function testAKeyIsUniqueInTheFile(): void
    {
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 09:30';
        $read = $this->importer()->read(['title' => 'T', 'posts' => [['key' => 'k', 'at' => $at, 'networks' => ['linkedin'], 'text' => 'One'], ['key' => 'k', 'at' => $at, 'networks' => ['linkedin'], 'text' => 'Two']]], $this->dir);

        $this->assertStringContainsString('the key "k" is already the one of post 1', implode("\n", $read['errors']));
    }

    // A keyed post imported again: unchanged it is left as it is, changed it is updated and taken back to a draft, gone out it is never touched
    public function testAKeyedPostIsImportedAgainRatherThanTwice(): void
    {
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 09:30';
        $file = ['title' => 'T', 'posts' => [['key' => 'k', 'at' => $at, 'networks' => ['linkedin'], 'text' => 'One idea']]];
        $series = new SocialSeries('T', 1, 'days', 1, [], SocialSeriesGenerator::MODE_IMPORT, '', [], ['linkedin']);
        new \ReflectionProperty(SocialSeries::class, 'id')->setValue($series, 7);

        // The first import gives the post its key and hash
        $first = $this->importer();
        $post = $first->import('T', $first->read($file, $this->dir)['drafts'])['created'][0];
        $this->assertSame('k', $post->getImportKey());
        $post->approve();

        $unchanged = $this->importer($series, $post);
        $result = $unchanged->import('T', $unchanged->read($file, $this->dir)['drafts']);
        $this->assertSame(1, $result['unchanged']);
        $this->assertTrue($post->isApproved());

        $file['posts'][0]['text'] = 'One better idea';
        $changed = $this->importer($series, $post);
        $result = $changed->import('T', $changed->read($file, $this->dir)['drafts']);
        $this->assertSame([$post], $result['updated']);
        $this->assertSame('One better idea', $post->getText());
        $this->assertFalse($post->isApproved());

        foreach ($post->getTargets() as $target) {
            $target->markPublished('external');
        }
        $file['posts'][0]['text'] = 'Too late';
        $out = $this->importer($series, $post);
        $this->assertSame(1, $out->import('T', $out->read($file, $this->dir)['drafts'])['out']);
        $this->assertSame('One better idea', $post->getText());

        // Its moment since passed, the file is still read rather than refused
        $file['posts'][0]['at'] = new \DateTimeImmutable('-1 day')->format('Y-m-d H:i');
        $past = $this->importer($series, $post);
        $read = $past->read($file, $this->dir);
        $this->assertSame([], $read['errors']);
        $this->assertSame(1, $past->import('T', $read['drafts'])['out']);
    }

    // A post a former import made without a key, at the same moment on the same networks, takes its key: unchanged it keeps its approval, changed it is updated
    public function testAKeylessPostOfAFormerImportIsTakenUp(): void
    {
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 09:30';
        $file = ['title' => 'T', 'posts' => [['key' => 'k', 'at' => $at, 'networks' => ['bluesky', 'linkedin'], 'text' => 'One idea', 'source' => 'book:12']]];
        $series = new SocialSeries('T', 1, 'days', 1, [], SocialSeriesGenerator::MODE_IMPORT, '', [], ['linkedin']);
        new \ReflectionProperty(SocialSeries::class, 'id')->setValue($series, 7);
        $legacy = new SocialPost(SocialPost::SOURCE_MANUAL, 'id', '', '', null, new \DateTimeImmutable($at));
        new SocialPostTarget($legacy, 'linkedin', 'One idea');
        new SocialPostTarget($legacy, 'bluesky', 'One idea');
        $legacy->setText('One idea');
        $legacy->approve();

        $importer = $this->importer($series, null, [$legacy]);
        $this->assertSame(1, $importer->import('T', $importer->read($file, $this->dir)['drafts'])['unchanged']);
        $this->assertSame('k', $legacy->getImportKey());
        $this->assertSame('book:12', $legacy->getSourceType() . ':' . $legacy->getSourceId());
        $this->assertTrue($legacy->isApproved());
    }

    // A post a former import made without a key, whose first comment the file changes, is updated rather than taken up as it is
    public function testAKeylessPostWhoseCommentChangedIsUpdated(): void
    {
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 09:30';
        $file = ['title' => 'T', 'posts' => [['key' => 'k', 'at' => $at, 'networks' => ['linkedin'], 'text' => 'One idea', 'comment' => 'Read more']]];
        $legacy = $this->legacy($at);
        $legacy->approve();

        $importer = $this->importer($this->importedSeries(), null, [$legacy]);
        $this->assertSame([$legacy], $importer->import('T', $importer->read($file, $this->dir)['drafts'])['updated']);
        $this->assertSame('Read more', $legacy->getTargets()->first()->getComment());
        $this->assertFalse($legacy->isApproved());
    }

    // Two keyed drafts at the same moment on the same networks never take the same keyless post: the second one is made
    public function testAKeylessPostIsTakenUpOnce(): void
    {
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 09:30';
        $file = ['title' => 'T', 'posts' => [['key' => 'a', 'at' => $at, 'networks' => ['linkedin'], 'text' => 'One idea'], ['key' => 'b', 'at' => $at, 'networks' => ['linkedin'], 'text' => 'Another idea']]];
        $legacy = $this->legacy($at);

        $importer = $this->importer($this->importedSeries(), null, [$legacy]);
        $result = $importer->import('T', $importer->read($file, $this->dir)['drafts']);

        $this->assertSame('a', $legacy->getImportKey());
        $this->assertCount(1, $result['created']);
        $this->assertSame('b', $result['created'][0]->getImportKey());
    }

    // A keyed post updated keeps its picture when it is the same, and the order of its networks changes nothing
    public function testAnUpdateKeepsTheSamePictureAndTheNetworksInAnyOrder(): void
    {
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 09:30';
        $file = ['title' => 'T', 'posts' => [['key' => 'k', 'at' => $at, 'networks' => ['bluesky', 'linkedin'], 'text' => 'One idea', 'media' => 'card.png']]];
        $first = $this->importer();
        $post = $first->import('T', $first->read($file, $this->dir)['drafts'])['created'][0];
        $media = $post->getMedias()->first();
        // The copy stored holds the picture given
        file_put_contents($this->dir . '/public/medias/card.jpg', 'png');
        $post->approve();

        $file['posts'][0]['networks'] = ['linkedin', 'bluesky'];
        $reordered = $this->importer($this->importedSeries(), $post);
        $this->assertSame(1, $reordered->import('T', $reordered->read($file, $this->dir)['drafts'])['unchanged']);
        $this->assertTrue($post->isApproved());

        $file['posts'][0]['text'] = 'One better idea';
        $changed = $this->importer($this->importedSeries(), $post);
        $this->assertSame([$post], $changed->import('T', $changed->read($file, $this->dir)['drafts'])['updated']);
        $this->assertSame([$media], $post->getMedias()->toArray());
    }

    // A file giving no post a key makes a new series, even when one of its title was imported, and says so
    public function testAFileWithoutKeysMakesANewSeries(): void
    {
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 09:30';
        $series = $this->importedSeries();
        $importer = $this->importer($series);
        $read = $importer->read(['title' => 'T', 'posts' => [['at' => $at, 'networks' => ['linkedin'], 'text' => 'One idea']]], $this->dir);
        $this->assertStringContainsString('imported as a new series', implode("\n", $read['notes']));

        $post = $importer->import('T', $read['drafts'])['created'][0];
        $this->assertNotSame($series, $post->getSeries());
        $this->assertInstanceOf(SocialSeries::class, $post->getSeries());
    }

    // A post a former import made without a key, on LinkedIn at $at, saying "One idea"
    private function legacy(string $at, string $url = ''): SocialPost
    {
        $post = new SocialPost(SocialPost::SOURCE_MANUAL, 'id', '', $url, null, new \DateTimeImmutable($at));
        new SocialPostTarget($post, 'linkedin', 'One idea');

        return $post->setText('One idea');
    }

    // The series "T" a former import made, saved
    private function importedSeries(): SocialSeries
    {
        $series = new SocialSeries('T', 1, 'days', 1, [], SocialSeriesGenerator::MODE_IMPORT, '', [], ['linkedin']);
        new \ReflectionProperty(SocialSeries::class, 'id')->setValue($series, 7);

        return $series;
    }

    // A keyed post gone by never stops the file: one already there is left as it is, one missing is not made
    public function testAKeyedPostGoneByIsLeftAsItIs(): void
    {
        $file = ['title' => 'T', 'posts' => [['key' => 'k', 'at' => '2020-01-06 09:30', 'networks' => ['linkedin'], 'text' => 'Old idea']]];
        $series = new SocialSeries('T', 1, 'days', 1, [], SocialSeriesGenerator::MODE_IMPORT, '', [], ['linkedin']);
        new \ReflectionProperty(SocialSeries::class, 'id')->setValue($series, 7);

        $importer = $this->importer($series);
        $read = $importer->read($file, $this->dir);
        $this->assertSame([], $read['errors']);
        $result = $importer->import('T', $read['drafts']);
        $this->assertSame(1, $result['past']);
        $this->assertSame([], $result['created']);
    }

    // A draft naming the content it was written from holds it, as a series' draft does, its link the content's when it gives none; a content the site does not have is refused
    public function testADraftHoldsTheContentItNames(): void
    {
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 09:30';
        $importer = $this->importer();

        $wrong = $importer->read(['title' => 'T', 'posts' => [['at' => $at, 'networks' => ['linkedin'], 'text' => 'One idea', 'source' => 'book:99']]], $this->dir);
        $this->assertStringContainsString('the source "book:99" is no content of this site', implode("\n", $wrong['errors']));

        $read = $importer->read(['title' => 'T', 'posts' => [['at' => $at, 'networks' => ['linkedin'], 'text' => 'One idea', 'source' => 'book:12']]], $this->dir);
        $post = $importer->import('T', $read['drafts'])['created'][0];

        $this->assertSame('book', $post->getSourceType());
        $this->assertSame('12', $post->getSourceId());
        $this->assertSame('One idea', $post->getText());
        $this->assertSame('https://example.org/histoire/cerf-volant', $post->getUrl());
    }

    // A post a former import made without a key, adopted unchanged with the content it now names, keeps the link the file gives it rather than the content's
    public function testAnAdoptedPostKeepsTheLinkOfTheFile(): void
    {
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 09:30';
        $file = ['title' => 'T', 'posts' => [['key' => 'k', 'at' => $at, 'networks' => ['linkedin'], 'text' => 'One idea', 'url' => 'https://example.org/own', 'source' => 'book:12']]];
        $legacy = $this->legacy($at, 'https://example.org/own');

        $importer = $this->importer($this->importedSeries(), null, [$legacy]);
        $this->assertSame(1, $importer->import('T', $importer->read($file, $this->dir)['drafts'])['unchanged']);
        $this->assertSame('12', $legacy->getSourceId());
        $this->assertSame('https://example.org/own', $legacy->getUrl());
    }

    // A file giving some posts a key gives one to every post, a post without one being made again at each import
    public function testAKeyedFileKeysEveryPost(): void
    {
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 09:30';
        $read = $this->importer()->read(['title' => 'T', 'posts' => [['key' => 'k', 'at' => $at, 'networks' => ['linkedin'], 'text' => 'One'], ['at' => $at, 'networks' => ['linkedin'], 'text' => 'Two']]], $this->dir);

        $this->assertSame(['Post 2: "key" is missing, the other posts giving one.'], $read['errors']);
    }

    // A keyed post whose source left the file is a post written by hand again, its content freed, its title read from its text
    public function testASourceLeavingTheFileFreesTheContent(): void
    {
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 09:30';
        $file = ['title' => 'T', 'posts' => [['key' => 'k', 'at' => $at, 'networks' => ['linkedin'], 'text' => 'One idea', 'source' => 'book:12']]];
        $first = $this->importer();
        $post = $first->import('T', $first->read($file, $this->dir)['drafts'])['created'][0];
        $this->assertSame('book', $post->getSourceType());

        unset($file['posts'][0]['source']);
        $again = $this->importer($this->importedSeries(), $post);
        $this->assertSame([$post], $again->import('T', $again->read($file, $this->dir)['drafts'])['updated']);
        $this->assertTrue($post->isManual());
        $this->assertSame('One idea', $post->getTitle());
        $this->assertSame('', $post->getUrl());
    }

    // A content held by a post to come of another series is refused; the posts of the file share one, the same content on several networks or days, and keep it when imported again
    public function testASourceAlreadyHeldIsRefused(): void
    {
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 09:30';
        $post = ['key' => 'k', 'at' => $at, 'networks' => ['linkedin'], 'text' => 'One idea', 'source' => 'book:12'];

        $held = $this->importer(held: ['book:12'])->read(['title' => 'T', 'posts' => [$post]], $this->dir);
        $this->assertStringContainsString('the source "book:12" is already held by a post to come of another series', implode("\n", $held['errors']));

        $shared = $this->importer()->read(['title' => 'T', 'posts' => [$post, ['key' => 'l', 'networks' => ['bluesky']] + $post]], $this->dir);
        $this->assertSame([], $shared['errors']);

        $first = $this->importer();
        $own = $first->import('T', $first->read(['title' => 'T', 'posts' => [$post]], $this->dir)['drafts'])['created'][0];
        $again = $this->importer($this->importedSeries(), $own, [], ['book:12']);
        $this->assertSame([], $again->read(['title' => 'T', 'posts' => [$post]], $this->dir)['errors']);
    }

    // A keyed file takes up the series a keyed import made, not a later one of its title imported without any key
    public function testTheSeriesMadeWithKeysIsTakenUp(): void
    {
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 09:30';
        $file = ['title' => 'T', 'posts' => [['key' => 'k', 'at' => $at, 'networks' => ['linkedin'], 'text' => 'One idea']]];
        $first = $this->importer();
        $post = $first->import('T', $first->read($file, $this->dir)['drafts'])['created'][0];
        $later = new SocialSeries('T', 1, 'days', 1, [], SocialSeriesGenerator::MODE_IMPORT, '', [], ['linkedin']);
        new \ReflectionProperty(SocialSeries::class, 'id')->setValue($later, 8);

        $again = $this->importer($later, $post, keyed: $this->importedSeries());
        $this->assertSame(1, $again->import('T', $again->read($file, $this->dir)['drafts'])['unchanged']);
    }

    // A keyed post to come whose key left the file is taken back to a draft and said, and the series taken up counts what it holds
    public function testAPostLeavingTheFileIsTakenBackToADraft(): void
    {
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 09:30';
        $first = $this->importer();
        $old = $first->import('T', $first->read(['title' => 'T', 'posts' => [['key' => 'intro', 'at' => $at, 'networks' => ['linkedin'], 'text' => 'One idea']]], $this->dir)['drafts'])['created'][0];
        $old->approve();

        $series = $this->importedSeries();
        $renamed = $this->importer($series, $old);
        $read = $renamed->read(['title' => 'T', 'posts' => [['key' => 'intro-1', 'at' => $at, 'networks' => ['bluesky'], 'text' => 'One idea']]], $this->dir);
        $this->assertStringContainsString('Posts "intro" are no longer in the file', implode("\n", $read['notes']));

        $this->assertCount(1, $renamed->import('T', $read['drafts'])['created']);
        $this->assertFalse($old->isApproved());
        $this->assertSame(2, $series->getCount());
        $this->assertSame(['linkedin', 'bluesky'], $series->getNetworks());
    }

    // A draft naming a content without "media" goes out with no picture of the content's, the media still required where the network asks for one
    public function testADraftWithoutMediaLeavesTheContentsPicture(): void
    {
        $at = new \DateTimeImmutable('+7 days')->format('Y-m-d') . ' 09:30';
        $importer = $this->importer();
        $post = $importer->import('T', $importer->read(['title' => 'T', 'posts' => [['at' => $at, 'networks' => ['linkedin'], 'text' => 'One idea', 'source' => 'book:13']]], $this->dir)['drafts'])['created'][0];

        $this->assertSame('13', $post->getSourceId());
        $this->assertNull($post->getImageUrl());
    }
}
