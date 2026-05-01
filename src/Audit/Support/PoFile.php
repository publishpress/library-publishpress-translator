<?php

/**
 * Read-only .po/.pot wrapper around gettext loaders (AST, no regex on entries).
 *
 * @todo Migrate Translator regex PO parsing to this wrapper.
 *
 * @package PublishPress\Translations\Audit\Support
 */

namespace PublishPress\Translations\Audit\Support;

use Gettext\Loader\PoLoader;
use Gettext\Loader\StrictPoLoader;
use Gettext\Translation;
use Gettext\Translations;
use Throwable;

final class PoFile
{
    /** @var Translations */
    private $translations;

    /** @var bool */
    private $parsedWithStrictLoader;

    /** @var string|null */
    private $parseWarning;

    private function __construct(Translations $translations, bool $parsedWithStrictLoader, ?string $parseWarning)
    {
        $this->translations          = $translations;
        $this->parsedWithStrictLoader = $parsedWithStrictLoader;
        $this->parseWarning            = $parseWarning;
    }

    public static function fromFile(string $path, bool $strictFirst): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read PO file: {$path}");
        }

        return self::fromString($raw, $strictFirst);
    }

    public static function fromString(string $contents, bool $strictFirst): self
    {
        $warning = null;
        if ($strictFirst) {
            try {
                $loader = new StrictPoLoader();
                $t      = $loader->loadString($contents);

                return new self($t, true, null);
            } catch (Throwable $e) {
                $warning = 'Strict PO parse failed; used lenient PoLoader (' . $e->getMessage() . ').';
            }
        }

        $loader = new PoLoader();
        $t      = $loader->loadString($contents);

        return new self($t, false, $warning);
    }

    public function translations(): Translations
    {
        return $this->translations;
    }

    public function parsedWithStrictLoader(): bool
    {
        return $this->parsedWithStrictLoader;
    }

    public function parseWarning(): ?string
    {
        return $this->parseWarning;
    }

    /**
     * Header map (Project-Id-Version, etc.).
     */
    public function header(string $name): ?string
    {
        return $this->translations->getHeaders()->get($name);
    }

    /**
     * @return array<string,bool> msgid keys (context\004msgid) for non-header entries
     */
    public function msgidKeySet(): array
    {
        $set = [];
        foreach ($this->translations as $t) {
            /** @var Translation $t */
            if ($t->isDisabled()) {
                continue;
            }
            if ($t->getOriginal() === '') {
                continue;
            }
            $set[$t->getId()] = true;
        }

        return $set;
    }

    /**
     * @return Translation[]
     */
    public function activeTranslations(): array
    {
        $list = [];
        foreach ($this->translations as $t) {
            /** @var Translation $t */
            if ($t->isDisabled()) {
                continue;
            }
            if ($t->getOriginal() === '') {
                continue;
            }
            $list[] = $t;
        }

        return $list;
    }

    /**
     * @return Translation[]
     */
    public function untranslatedEntries(): array
    {
        $out = [];
        foreach ($this->activeTranslations() as $t) {
            if ($t->getFlags()->has('fuzzy')) {
                continue;
            }
            if ($this->entryHasEmptyTranslation($t)) {
                $out[] = $t;
            }
        }

        return $out;
    }

    /**
     * @return Translation[]
     */
    public function fuzzyEntries(): array
    {
        $out = [];
        foreach ($this->activeTranslations() as $t) {
            if ($t->getFlags()->has('fuzzy')) {
                $out[] = $t;
            }
        }

        return $out;
    }

    private function entryHasEmptyTranslation(Translation $t): bool
    {
        if ($t->getPlural() !== null && $t->getPlural() !== '') {
            $forms = $t->getPluralTranslations();
            if ($t->getTranslation() === null || $t->getTranslation() === '') {
                return true;
            }
            foreach ($forms as $v) {
                if ($v === null || $v === '') {
                    return true;
                }
            }

            return false;
        }

        return $t->getTranslation() === null || $t->getTranslation() === '';
    }

    /**
     * Serialize singular + plural forms for diff comparison.
     */
    public static function serializeTranslation(Translation $t): string
    {
        if ($t->getPlural() !== null && $t->getPlural() !== '') {
            $parts   = [];
            $parts[] = (string) $t->getTranslation();
            foreach ($t->getPluralTranslations() as $p) {
                $parts[] = (string) $p;
            }

            return implode("\u{241E}", $parts);
        }

        return (string) $t->getTranslation();
    }
}
