<?php

declare(strict_types=1);

namespace PublishPress\Translations\Tests;

use PHPUnit\Framework\TestCase;
use PublishPress\Translations\PoCatalog;
use PublishPress\Translations\PoCatalogProcessor;

class PoCatalogProcessorTest extends TestCase
{
    /**
     * @var PoCatalogProcessor
     */
    private $processor;

    protected function setUp(): void
    {
        $this->processor = new PoCatalogProcessor();
    }

    public function testNormalizeForWeblateFixesBrokenPluralHeadersAndPluralSlots(): void
    {
        $poFile = $this->copyFixtureToTemp('broken-plural-header.po');

        $this->processor->normalizeForWeblate('pl_PL', $poFile);

        $content = (string) file_get_contents($poFile);
        $this->assertStringNotContainsString('"&& (n % 100 < 10 || n % 100 >= 20) ? 1 : 2);\\n"', $content);
        $this->assertStringContainsString('"Language: pl_PL\\n"', $content);
        $this->assertStringContainsString('"Plural-Forms: nplurals=3; plural=(n == 1 ? 0 : n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20) ? 1 : 2);\\n"', $content);

        $catalog = PoCatalog::fromFile($poFile);
        $this->assertNotNull($catalog);

        $entry = $catalog->findEntry(null, '%d file');
        $this->assertNotNull($entry);
        $this->assertSame('plik', $entry->getTranslation());
        $this->assertSame(['', ''], $entry->getPluralTranslations(2));
    }

    public function testCanonicalizeRemovesDuplicateHeadersAndReferenceDuplicates(): void
    {
        $poFile = $this->copyFixtureToTemp('duplicate-headers-and-references.po');

        $this->processor->canonicalize($poFile);

        $content = (string) file_get_contents($poFile);
        $this->assertSame(1, substr_count($content, 'msgid ""'));
        $this->assertSame(1, substr_count($content, '#: fake.php:10'));
    }

    public function testNormalizeAfterTranslationRepairsPipePluralAndKeepsPluginNameUntranslatedAndMarksFuzzy(): void
    {
        $poFile = $this->copyFixtureToTemp('post-translation-mutations.po');

        $this->processor->normalizeAfterTranslation($poFile, 'Fake Plugin');

        $catalog = PoCatalog::fromFile($poFile);
        $this->assertNotNull($catalog);

        $pluginEntry = $catalog->findEntry(null, 'Fake Plugin');
        $this->assertNotNull($pluginEntry);
        $this->assertSame('Fake Plugin', $pluginEntry->getTranslation());

        $identicalEntry = $catalog->findEntry(null, 'Settings');
        $this->assertNotNull($identicalEntry);
        $this->assertTrue($identicalEntry->getFlags()->has('fuzzy'));

        $pluralEntry = $catalog->findEntry(null, '%d item');
        $this->assertNotNull($pluralEntry);
        $this->assertSame('element', $pluralEntry->getTranslation());
        $this->assertSame(['elementy', 'elementow'], $pluralEntry->getPluralTranslations(2));
    }

    public function testNormalizeForWeblateRemovesHeaderFuzzy(): void
    {
        $poFile = $this->copyFixtureToTemp('header-fuzzy.po');
        $this->processor->normalizeForWeblate('pl_PL', $poFile);

        $content = (string) file_get_contents($poFile);
        $this->assertStringNotContainsString('#, fuzzy', $content);
    }

    /**
     * @param string $fixtureName
     * @return string
     */
    private function copyFixtureToTemp($fixtureName): string
    {
        $fixturePath = __DIR__ . '/fixtures/' . $fixtureName;
        $this->assertFileExists($fixturePath);

        $tempPath = tempnam(sys_get_temp_dir(), 'po_fixture_');
        $this->assertNotFalse($tempPath);
        copy($fixturePath, $tempPath);

        return $tempPath;
    }
}
