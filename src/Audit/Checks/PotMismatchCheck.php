<?php

/**
 * .po msgid keys vs .pot canonical set (read-only).
 *
 * @package PublishPress\Translations\Audit\Checks
 */

namespace PublishPress\Translations\Audit\Checks;

use PublishPress\Translations\Audit\AuditCheckInterface;
use PublishPress\Translations\Audit\AuditContext;
use PublishPress\Translations\Audit\AuditFinding;
use PublishPress\Translations\Audit\CheckId;
use PublishPress\Translations\Audit\Support\PoFile;

final class PotMismatchCheck implements AuditCheckInterface
{
    public function id(): string
    {
        return CheckId::POT_MISMATCH;
    }

    public function title(): string
    {
        return 'POT vs PO msgid set mismatch';
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
                'No .pot files in languages/.',
                null,
                null,
                null
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
                    null
                );
                continue;
            }

            $potKeys = $potPo->msgidKeySet();

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
                        null
                    );
                    continue;
                }

                $poKeys = $localePo->msgidKeySet();
                $orphan = array_diff_key($poKeys, $potKeys);
                $miss   = array_diff_key($potKeys, $poKeys);

                if ($orphan === [] && $miss === []) {
                    continue;
                }

                $findings[] = new AuditFinding(
                    $this->id(),
                    'warning',
                    $relPo,
                    $locale,
                    sprintf(
                        'vs %s: orphans=%d missing=%d | orphan sample: %s | missing sample: %s',
                        basename($potPath),
                        count($orphan),
                        count($miss),
                        self::sampleKeys($orphan, 5),
                        self::sampleKeys($miss, 5)
                    ),
                    null,
                    null,
                    null
                );
            }
        }

        return $findings;
    }

    /**
     * @param array<string,bool> $set
     */
    private static function sampleKeys(array $set, int $max): string
    {
        $keys = array_keys($set);
        $keys  = array_slice($keys, 0, $max);
        $out   = [];
        foreach ($keys as $k) {
            $out[] = str_replace("\004", '|', $k);
        }

        return $out === [] ? '(none)' : implode('; ', $out);
    }
}
