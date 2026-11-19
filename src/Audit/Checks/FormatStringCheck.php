<?php

/**
 * Printf placeholder and escaped-quote mismatches in .po msgstr values.
 *
 * Read-only. Repair with --repair-placeholders. A mismatch can fatal in PHP 8
 * when WordPress passes the translation to sprintf.
 *
 * @package PublishPress\Translations\Audit\Checks
 */

namespace PublishPress\Translations\Audit\Checks;

use Gettext\Translation;
use PublishPress\Translations\Audit\AuditCheckInterface;
use PublishPress\Translations\Audit\AuditContext;
use PublishPress\Translations\Audit\AuditFinding;
use PublishPress\Translations\Audit\CheckId;
use PublishPress\Translations\Audit\IssueSlug;
use PublishPress\Translations\Audit\Support\PoFile;
use PublishPress\Translations\Support\PrintfPlaceholder;

final class FormatStringCheck implements AuditCheckInterface
{
    public function id(): string
    {
        return CheckId::FORMAT_STRING;
    }

    public function title(): string
    {
        return 'Format strings (.po)';
    }

    public function run(AuditContext $ctx): array
    {
        $findings = [];
        $strict   = $ctx->options()->strictPo();
        $dir      = $ctx->languagesDir();

        foreach ($ctx->targetLanguages() as $locale) {
            $pattern = $dir . '/*-' . $locale . '.po';
            $files   = glob($pattern) ?: [];
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

                $lines = [];
                foreach ($po->activeTranslations() as $translation) {
                    foreach (self::badForms($translation) as $line) {
                        $lines[] = $line;
                    }
                }

                if ($lines === []) {
                    continue;
                }

                $summary = 'format=' . count($lines);
                $preview = self::cliPreview($lines, 5);
                $message = $summary . '. Run --repair-placeholders.';
                if ($preview !== '') {
                    $message .= ' | ' . $preview;
                }

                $findings[] = new AuditFinding(
                    $this->id(),
                    'error',
                    $rel,
                    $locale,
                    $message,
                    null,
                    null,
                    null,
                    $lines,
                    $summary,
                    IssueSlug::FORMAT_PLACEHOLDER
                );
            }
        }

        return $findings;
    }

    /**
     * @return string[]
     */
    private static function badForms(Translation $translation): array
    {
        $msgid  = $translation->getOriginal();
        $plural = $translation->hasPlural() ? $translation->getPlural() : '';
        $forms  = [(string) $translation->getTranslation()];
        if ($plural !== '') {
            foreach ($translation->getPluralTranslations() as $form) {
                $forms[] = (string) $form;
            }
        }

        $lines = [];
        foreach ($forms as $index => $value) {
            if ($value === '') {
                continue;
            }
            $source = ($index === 0 || $plural === '') ? $msgid : $plural;
            $result = PrintfPlaceholder::align($source, $value);
            if ($result['action'] === 'ok' || $result['action'] === 'skip') {
                continue;
            }

            $label   = $plural !== '' ? 'msgstr[' . $index . ']' : 'msgstr';
            $lines[] = $result['action'] . ' ' . $label . ' — ' . $result['reason']
                . ' — ' . self::clip($msgid);
        }

        return $lines;
    }

    /**
     * @param string[] $lines
     */
    private static function cliPreview(array $lines, int $limit): string
    {
        $sample = array_slice($lines, 0, $limit);

        return implode('; ', $sample);
    }

    private static function clip(string $value): string
    {
        $flat = str_replace(["\r", "\n"], ' ', $value);
        if (strlen($flat) <= 80) {
            return $flat;
        }

        return substr($flat, 0, 77) . '...';
    }
}
