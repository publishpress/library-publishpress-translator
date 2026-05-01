<?php

/**
 * Fuzzy-flagged entries per locale .po (read-only).
 *
 * @package PublishPress\Translations\Audit\Checks
 */

namespace PublishPress\Translations\Audit\Checks;

use PublishPress\Translations\Audit\AuditCheckInterface;
use PublishPress\Translations\Audit\AuditContext;
use PublishPress\Translations\Audit\AuditFinding;
use PublishPress\Translations\Audit\CheckId;
use PublishPress\Translations\Audit\Support\PoFile;

final class FuzzyTranslationCheck implements AuditCheckInterface
{
    public function id(): string
    {
        return CheckId::FUZZY_TRANSLATION;
    }

    public function title(): string
    {
        return 'Fuzzy translations (.po)';
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
                        null
                    );
                    continue;
                }

                $w = $po->parseWarning();
                if ($w !== null) {
                    $findings[] = new AuditFinding($this->id(), 'warning', $rel, $locale, $w, null, null, null);
                }

                $fuzzy = $po->fuzzyEntries();
                if ($fuzzy === []) {
                    continue;
                }

                $detailLines = self::msgidLinesForReport($fuzzy);
                $summary     = 'fuzzy=' . count($fuzzy);
                $preview     = self::cliPreviewFromLines($detailLines, 8);
                $msg         = $summary;
                if ($preview !== '') {
                    $msg .= ' | fuzzy msgid sample: ' . $preview;
                }

                $findings[] = new AuditFinding(
                    $this->id(),
                    'info',
                    $rel,
                    $locale,
                    $msg,
                    null,
                    null,
                    null,
                    $detailLines,
                    $summary
                );
            }
        }

        return $findings;
    }

    /**
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
