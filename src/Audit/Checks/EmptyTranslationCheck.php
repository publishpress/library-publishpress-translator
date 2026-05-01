<?php

/**
 * Empty msgstr counts per locale .po (read-only). Fuzzy entries are reported by FuzzyTranslationCheck.
 *
 * @package PublishPress\Translations\Audit\Checks
 */

namespace PublishPress\Translations\Audit\Checks;

use PublishPress\Translations\Audit\AuditCheckInterface;
use PublishPress\Translations\Audit\AuditContext;
use PublishPress\Translations\Audit\AuditFinding;
use PublishPress\Translations\Audit\CheckId;
use PublishPress\Translations\Audit\IssueSlug;
use PublishPress\Translations\Audit\Support\PoFile;
use PublishPress\Translations\Support\TranslationOverrides;

final class EmptyTranslationCheck implements AuditCheckInterface
{
    public function id(): string
    {
        return CheckId::EMPTY_TRANSLATION;
    }

    public function title(): string
    {
        return 'Empty translations (.po)';
    }

    public function run(AuditContext $ctx): array
    {
        $findings = [];
        $strict   = $ctx->options()->strictPo();
        $dir      = $ctx->languagesDir();

        foreach ($ctx->targetLanguages() as $locale) {
            $pattern = $dir . '/*-' . $locale . '.po';
            $files     = glob($pattern) ?: [];
            foreach ($files as $file) {
                $rel = ltrim(str_replace($ctx->pluginRoot(), '', $file), '/\\');
                try {
                    $po = PoFile::fromFile($file, $strict);
                } catch (\Throwable $e) {
                    $findings[] = new AuditFinding(
                        $this->id(),
                        'warning',
                        $rel,
                        $locale,
                        'Parse error: ' . $e->getMessage(),
                        null,
                        null,
                        null,
                        null,
                        null,
                        IssueSlug::PO_PARSE_ERROR
                    );
                    continue;
                }

                $w = $po->parseWarning();
                if ($w !== null) {
                    $findings[] = new AuditFinding(
                        $this->id(),
                        'warning',
                        $rel,
                        $locale,
                        $w,
                        null,
                        null,
                        null,
                        null,
                        null,
                        IssueSlug::PO_PARSE_WARNING
                    );
                }

                $empty = $po->untranslatedEntries();
                $empty = self::withoutTranslationOverrideMsgids($empty, TranslationOverrides::mapForLanguage($locale));
                if ($empty === []) {
                    continue;
                }

                $detailLines = self::msgidLinesForReport($empty);
                $summary     = 'empty=' . count($empty);
                $preview     = self::cliPreviewFromLines($detailLines, 8);
                $msg         = $summary;
                if ($preview !== '') {
                    $msg .= ' | empty msgid sample: ' . $preview;
                }

                $findings[] = new AuditFinding(
                    $this->id(),
                    'warning',
                    $rel,
                    $locale,
                    $msg,
                    null,
                    null,
                    null,
                    $detailLines,
                    $summary,
                    IssueSlug::EMPTY_TRANSLATION
                );
            }
        }

        return $findings;
    }

    /**
     * Drop entries whose msgid exactly matches a TRANSLATION_OVERRIDES source for this locale
     * (same keys as Translator::getOverridesForLanguage): those strings are intentionally
     * fixed to a known target (often msgid === msgstr) and must not count as missing translations.
     *
     * @param array<int,\Gettext\Translation> $entries
     * @param array<string, string>           $overrideMap
     *
     * @return array<int,\Gettext\Translation>
     */
    private static function withoutTranslationOverrideMsgids(array $entries, array $overrideMap): array
    {
        if ($entries === [] || $overrideMap === []) {
            return $entries;
        }

        $out = [];
        foreach ($entries as $t) {
            if (array_key_exists($t->getOriginal(), $overrideMap)) {
                continue;
            }
            $out[] = $t;
        }

        return $out;
    }

    /**
     * Full msgid/context lines for file reports (not truncated).
     *
     * @param array<int,\Gettext\Translation> $entries
     *
     * @return string[]
     */
    private static function msgidLinesForReport(array $entries): array
    {
        $out = [];
        foreach ($entries as $t) {
            $c = $t->getContext();
            $m = $t->getOriginal();
            $out[] = ($c !== null && $c !== '' ? '[' . $c . '] ' : '') . $m;
        }

        return $out;
    }

    /**
     * @param string[] $lines
     */
    private static function cliPreviewFromLines(array $lines, int $max): string
    {
        $bits = [];
        foreach (array_slice($lines, 0, $max) as $line) {
            $bits[] = self::shorten($line);
        }

        return $bits === [] ? '' : implode('; ', $bits);
    }

    private static function shorten(string $s): string
    {
        if (strlen($s) <= 80) {
            return $s;
        }

        return substr($s, 0, 77) . '...';
    }
}
