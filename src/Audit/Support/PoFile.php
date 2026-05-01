<?php

/**
 * Read-only .po/.pot wrapper around gettext v4 (Translations::fromPoString).
 *
 * gettext/gettext is constrained to ^4.8 so this library coexists with
 * wp-cli/i18n-command (which requires gettext ^4.8). The --audit-strict-po flag
 * is reserved: strict parsing needs gettext v5+ and is not applied on v4.
 *
 * @todo Migrate Translator regex PO parsing to this wrapper.
 *
 * @package PublishPress\Translations\Audit\Support
 */

namespace PublishPress\Translations\Audit\Support;

use Gettext\Translation;
use Gettext\Translations;

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
        $this->translations           = $translations;
        $this->parsedWithStrictLoader  = $parsedWithStrictLoader;
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
            $warning = 'Strict PO parsing (--audit-strict-po) requires gettext/gettext ^5; '
                . 'this project uses gettext v4 for wp-cli compatibility — using standard parser.';
        }

        $t = Translations::fromPoString($contents);

        return new self($t, false, $warning);
    }

    public function translations(): Translations
    {
        return $this->translations;
    }

    /**
     * Find translation; null if missing (gettext v4 find() returns false).
     */
    public function find(?string $context, string $original): ?Translation
    {
        $ctx = $context === null ? '' : $context;
        $t   = $this->translations->find($ctx, $original);

        return $t === false ? null : $t;
    }

    public function parsedWithStrictLoader(): bool
    {
        return $this->parsedWithStrictLoader;
    }

    public function parseWarning(): ?string
    {
        return $this->parseWarning;
    }

    public function header(string $name): ?string
    {
        return $this->translations->getHeader($name);
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
            if (in_array('fuzzy', $t->getFlags(), true)) {
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
            if (in_array('fuzzy', $t->getFlags(), true)) {
                $out[] = $t;
            }
        }

        return $out;
    }

    private function entryHasEmptyTranslation(Translation $t): bool
    {
        if ($t->hasPlural()) {
            $forms = $t->getPluralTranslations();
            if (!$t->hasTranslation()) {
                return true;
            }
            foreach ($forms as $v) {
                if ($v === null || $v === '') {
                    return true;
                }
            }

            return false;
        }

        return !$t->hasTranslation();
    }

    /**
     * Serialize singular + plural forms for diff comparison.
     */
    public static function serializeTranslation(Translation $t): string
    {
        if ($t->hasPlural()) {
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
