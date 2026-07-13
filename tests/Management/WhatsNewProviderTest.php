<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\SocialBundle\Tests\Management;

use c975L\SocialBundle\Management\WhatsNewProvider;
use PHPUnit\Framework\TestCase;

class WhatsNewProviderTest extends TestCase
{
    private ?string $originalLocale = null;

    protected function setUp(): void
    {
        $this->originalLocale = \Locale::getDefault();
    }

    protected function tearDown(): void
    {
        \Locale::setDefault($this->originalLocale);
    }

    // Reads the same config/whatsnew.json the provider is expected to point at, decoded independently
    // so assertions stay valid as new release-note entries are appended over time
    private function readRawEntries(): array
    {
        return json_decode(
            file_get_contents(\dirname(__DIR__, 2) . '/config/whatsnew.json'),
            true
        );
    }

    // The provider must resolve to this bundle's own config/whatsnew.json, not another bundle's
    public function testGetEntriesReturnsOneEntryPerRecordInTheBundleOwnJsonFile(): void
    {
        $rawEntries = $this->readRawEntries();

        $entries = (new WhatsNewProvider())->getEntries();

        $this->assertCount(\count($rawEntries), $entries);
    }

    // Each entry's date must be parsed into a DateTimeImmutable matching the json "date" field
    public function testGetEntriesParsesDatesAsDateTimeImmutable(): void
    {
        $rawEntries = $this->readRawEntries();

        $entries = (new WhatsNewProvider())->getEntries();

        foreach ($entries as $index => $entry) {
            $this->assertInstanceOf(\DateTimeImmutable::class, $entry['date']);
            $this->assertSame($rawEntries[$index]['date'], $entry['date']->format('Y-m-d'));
        }
    }

    // Description resolution follows the current ICU default locale (delegated to WhatsNewJsonReader)
    public function testGetEntriesResolvesDescriptionsForCurrentLocale(): void
    {
        \Locale::setDefault('fr');
        $rawEntries = $this->readRawEntries();

        $entries = (new WhatsNewProvider())->getEntries();

        foreach ($entries as $index => $entry) {
            foreach ($entry['description'] as $descriptionIndex => $description) {
                $this->assertSame($rawEntries[$index]['description'][$descriptionIndex]['fr'], $description);
            }
        }
    }
}
