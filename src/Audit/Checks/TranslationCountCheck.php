<?php

/**
 * Counts translatable strings in the .pot and each locale .po file.
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

final class TranslationCountCheck implements AuditCheckInterface
{
    public function id(): string
    {
        return CheckId::TRANSLATION_COUNT;
    }

    public function title(): string
    {
        return 'Translation string counts';
    }

    public function run(AuditContext $ctx): array
    {
        $findings = [];
        $strict   = $ctx->options()->strictPo();
        $dir      = $ctx->languagesDir();

        $potFiles = glob($dir . '/*.pot') ?: [];
        if ($potFiles === []) {
            $findings[] = new AuditFinding(
                $this->id(),
                'info',
                '',
                '',
                'No .pot files — skipping string count.',
                null,
                null,
                null,
                null,
                null,
                IssueSlug::NO_POT_IN_LANGUAGES
            );

            return $findings;
        }

        foreach ($potFiles as $potPath) {
            $potBase = basename($potPath, '.pot');
            $relPot  = ltrim(str_replace($ctx->pluginRoot(), '', $potPath), '/\\');

            try {
                $potPo = PoFile::fromFile($potPath, $strict);
            } catch (\Throwable $e) {
                $findings[] = new AuditFinding(
                    $this->id(),
                    'warning',
                    $relPot,
                    '',
                    'Parse error: ' . $e->getMessage(),
                    null,
                    null,
                    null,
                    null,
                    null,
                    IssueSlug::POT_PARSE_ERROR
                );
                continue;
            }

            $potKeys  = $potPo->msgidKeySet();
            $potCount = count($potKeys);

            $findings[] = new AuditFinding(
                $this->id(),
                'info',
                $relPot,
                '',
                'POT has ' . $potCount . ' translatable string(s)',
                null,
                null,
                null,
                null,
                null,
                IssueSlug::POT_STRING_COUNT
            );

            foreach ($ctx->targetLanguages() as $locale) {
                $poPath = $dir . '/' . $potBase . '-' . $locale . '.po';
                if (!is_file($poPath)) {
                    continue;
                }

                $relPo = ltrim(str_replace($ctx->pluginRoot(), '', $poPath), '/\\');

                try {
                    $localePo = PoFile::fromFile($poPath, $strict);
                } catch (\Throwable $e) {
                    $findings[] = new AuditFinding(
                        $this->id(),
                        'warning',
                        $relPo,
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

                $poKeys  = $localePo->msgidKeySet();
                $poCount = count($poKeys);
                $extra   = count(array_diff_key($poKeys, $potKeys));
                $missing = count(array_diff_key($potKeys, $poKeys));

                $summary = sprintf(
                    '%d string(s) — %d extra, %d missing (POT: %d)',
                    $poCount,
                    $extra,
                    $missing,
                    $potCount
                );

                $severity = $missing > 0 ? 'warning' : 'info';
                $findings[] = new AuditFinding(
                    $this->id(),
                    $severity,
                    $relPo,
                    $locale,
                    $summary,
                    null,
                    null,
                    null,
                    null,
                    $summary,
                    IssueSlug::TRANSLATION_COUNT_SUMMARY
                );
            }
        }

        return $findings;
    }
}
