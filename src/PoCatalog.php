<?php

/**
 * PO catalog wrapper around gettext/gettext.
 *
 * @package PublishPress\Translations
 */

namespace PublishPress\Translations;

use Gettext\Generator\PoGenerator;
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

        $loader = new PoLoader();
        $translations = $loader->loadString($content);

        return new self($translations, $path, $content);
    }

    /**
     * @return Translations
     */
    public function getTranslations()
    {
        return $this->translations;
    }

    /**
     * @return array<string, Translation>
     */
    public function getEntries()
    {
        return $this->translations->getTranslations();
    }

    /**
     * @param string|null $context
     * @param string      $original
     * @return Translation|null
     */
    public function findEntry($context, $original)
    {
        return $this->translations->find($context, $original);
    }

    /**
     * Save only when serialized output differs.
     *
     * @return bool True when file was written.
     */
    public function saveIfChanged()
    {
        $generator = new PoGenerator();
        $newContent = $generator->generateString($this->translations);

        if ($newContent === $this->originalContent) {
            return false;
        }

        file_put_contents($this->path, $newContent);
        $this->originalContent = $newContent;

        return true;
    }
}
