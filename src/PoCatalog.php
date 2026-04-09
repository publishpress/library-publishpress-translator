<?php

/**
 * PO catalog wrapper around gettext/gettext.
 *
 * Abstracts the API differences between gettext/gettext v4 and v5 so callers
 * never need to branch on the installed version.
 *
 * @package PublishPress\Translations
 */

namespace PublishPress\Translations;

use Gettext\Extractors\Po as PoExtractorV4;
use Gettext\Generator\PoGenerator;
use Gettext\Generators\Po as PoGeneratorV4;
use Gettext\Loader\PoLoader;
use Gettext\Translation;
use Gettext\Translations;

class PoCatalog
{
    /**
     * Parsed PO translations catalog.
     *
     * @var Translations
     */
    private $translations;

    /**
     * Source file path.
     *
     * @var string
     */
    private $path;

    /**
     * Original file contents used to detect changes.
     *
     * @var string
     */
    private $originalContent;

    /**
     * @param Translations $translations
     * @param string       $path
     * @param string       $originalContent
     */
    private function __construct(Translations $translations, $path, $originalContent)
    {
        $this->translations = $translations;
        $this->path = $path;
        $this->originalContent = $originalContent;
    }

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /**
     * Load a PO file into an AST catalog.
     *
     * @param string $path
     * @return self|null
     */
    public static function fromFile($path)
    {
        $content = @file_get_contents($path);
        if ($content === false || $content === '') {
            return null;
        }

        if (class_exists(PoLoader::class)) {
            $loader = new PoLoader();
            $translations = $loader->loadString($content);
        } else {
            $translations = new Translations();
            PoExtractorV4::fromString($content, $translations);
        }

        return new self($translations, $path, $content);
    }

    // -------------------------------------------------------------------------
    // File-level header / flag operations
    // -------------------------------------------------------------------------

    /**
     * Delete a flag from the file-level (header) entry.
     *
     * Only meaningful in gettext/gettext v5; silently ignored for v4 which
     * does not expose file-level flags on the Translations object.
     *
     * @param string $flag
     * @return void
     */
    public function deleteFileFlag($flag)
    {
        if (class_exists(PoLoader::class)) {
            $this->translations->getFlags()->delete($flag);
        }
    }

    /**
     * Set a PO header value.
     *
     * @param string $name
     * @param string $value
     * @return void
     */
    public function setHeader($name, $value)
    {
        if (class_exists(PoLoader::class)) {
            $this->translations->getHeaders()->set($name, $value);
        } else {
            $this->translations->setHeader($name, $value);
        }
    }

    /**
     * Delete a PO header.
     *
     * @param string $name
     * @return void
     */
    public function deleteHeader($name)
    {
        if (class_exists(PoLoader::class)) {
            $this->translations->getHeaders()->delete($name);
        } else {
            $this->translations->deleteHeader($name);
        }
    }

    // -------------------------------------------------------------------------
    // Entry access
    // -------------------------------------------------------------------------

    /**
     * @return array<string, Translation>
     */
    public function getEntries()
    {
        if (class_exists(PoLoader::class)) {
            return $this->translations->getTranslations();
        }

        return iterator_to_array($this->translations);
    }

    /**
     * @param string|null $context
     * @param string      $original
     * @return Translation|null
     */
    public function findEntry($context, $original)
    {
        $result = $this->translations->find($context, $original);

        // v4 returns false when not found; normalise to null.
        return ($result !== false && $result !== null) ? $result : null;
    }

    // -------------------------------------------------------------------------
    // Static per-entry helpers (abstract v4 vs v5 Translation API)
    // -------------------------------------------------------------------------

    /**
     * Whether a translation entry has a plural form defined.
     *
     * v4: getPlural() returns '' for singular entries.
     * v5: getPlural() returns null for singular entries.
     *
     * @param Translation $entry
     * @return bool
     */
    public static function entryHasPlural(Translation $entry)
    {
        $plural = $entry->getPlural();

        return $plural !== null && $plural !== '';
    }

    /**
     * Set the singular translation string on an entry.
     *
     * @param Translation $entry
     * @param string      $value
     * @return void
     */
    public static function setEntryTranslation(Translation $entry, $value)
    {
        if (class_exists(PoLoader::class)) {
            $entry->translate($value);
        } else {
            $entry->setTranslation($value);
        }
    }

    /**
     * Set the plural translation strings on an entry.
     *
     * @param Translation $entry
     * @param string[]    $values Indexed from plural[0] onwards.
     * @return void
     */
    public static function setEntryPluralTranslations(Translation $entry, array $values)
    {
        if (class_exists(PoLoader::class)) {
            $entry->translatePlural(...$values);
        } else {
            $entry->setPluralTranslations($values);
        }
    }

    /**
     * Check whether an entry carries a specific flag.
     *
     * @param Translation $entry
     * @param string      $flag
     * @return bool
     */
    public static function entryHasFlag(Translation $entry, $flag)
    {
        if (class_exists(PoLoader::class)) {
            return $entry->getFlags()->has($flag);
        }

        return in_array($flag, $entry->getFlags(), true);
    }

    /**
     * Add a flag to an entry.
     *
     * @param Translation $entry
     * @param string      $flag
     * @return void
     */
    public static function addEntryFlag(Translation $entry, $flag)
    {
        if (class_exists(PoLoader::class)) {
            $entry->getFlags()->add($flag);
        } else {
            $entry->addFlag($flag);
        }
    }

    // -------------------------------------------------------------------------
    // Persistence
    // -------------------------------------------------------------------------

    /**
     * Save only when serialized output differs.
     *
     * @return bool True when file was written.
     */
    public function saveIfChanged()
    {
        if (class_exists(PoGenerator::class)) {
            $generator = new PoGenerator();
            $newContent = $generator->generateString($this->translations);
        } else {
            $newContent = PoGeneratorV4::toString($this->translations);
        }

        if ($newContent === $this->originalContent) {
            return false;
        }

        file_put_contents($this->path, $newContent);
        $this->originalContent = $newContent;

        return true;
    }
}
