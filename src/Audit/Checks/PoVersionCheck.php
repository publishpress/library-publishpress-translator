<?php

/**
 * Project-Id-Version header vs plugin version (read-only). Header fixes belong in the translation workflow.
 *
 * @package PublishPress\Translations\Audit\Checks
 */

namespace PublishPress\Translations\Audit\Checks;

use PublishPress\Translations\Audit\AuditCheckInterface;
use PublishPress\Translations\Audit\AuditContext;
use PublishPress\Translations\Audit\AuditFinding;
use PublishPress\Translations\Audit\CheckId;
use PublishPress\Translations\Audit\Support\PoFile;

final class PoVersionCheck implements AuditCheckInterface
{
    public function id(): string
    {
        return CheckId::PO_VERSION;
    }

    public function title(): string
    {
        return 'PO header Project-Id-Version vs plugin version';
    }

    public function run(AuditContext $ctx): array
    {
        $findings = [];
        $pluginV  = $ctx->pluginVersion();
        if ($pluginV === null || $pluginV === '') {
            $findings[] = new AuditFinding(
                $this->id(),
                'info',
                '',
                '',
                'Plugin version not detected from PHP headers — skipping version check.',
                null,
                null,
                null
            );

            return $findings;
        }

        $strict = $ctx->options()->strictPo();
        $dir    = $ctx->languagesDir();
        $want   = trim($ctx->pluginDisplayName()) . ' ' . trim($pluginV);

        foreach ($ctx->targetLanguages() as $locale) {
            $pattern = $dir . '/*-' . $locale . '.po';
            foreach (glob($pattern) ?: [] as $file) {
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

                $rawHeader = $po->header('Project-Id-Version');
                if ($rawHeader === null || $rawHeader === '') {
                    $msg     = 'Project-Id-Version header missing — set it via translation export / Weblate; this audit does not edit .po headers.';
                    $summary = 'Project-Id-Version header missing';
                    $details = ['Suggested Project-Id-Version:', $want];
                    $findings[] = new AuditFinding(
                        $this->id(),
                        'warning',
                        $rel,
                        $locale,
                        $msg,
                        null,
                        $want,
                        null,
                        $details,
                        $summary
                    );
                    continue;
                }

                $poVer = self::extractVersionToken($rawHeader);
                if ($poVer === null) {
                    $findings[] = new AuditFinding(
                        $this->id(),
                        'warning',
                        $rel,
                        $locale,
                        'Could not parse version from Project-Id-Version: ' . $rawHeader,
                        $rawHeader,
                        $want,
                        null
                    );
                    continue;
                }

                $cmp = version_compare($poVer, $pluginV);
                if ($cmp === 0) {
                    continue;
                }

                if ($cmp > 0) {
                    $findings[] = new AuditFinding(
                        $this->id(),
                        'warning',
                        $rel,
                        $locale,
                        'PO claims newer version (' . $poVer . ') than plugin (' . $pluginV . ') — not changed by this audit.',
                        $rawHeader,
                        null,
                        null
                    );
                    continue;
                }

                $msg     = 'Project-Id-Version outdated (' . $poVer . ' < ' . $pluginV . '). Update via translation export / Weblate — this audit does not edit .po headers.';
                $summary = 'Project-Id-Version outdated (' . $poVer . ' < ' . $pluginV . ')';
                $details = [
                    'Current header value:',
                    $rawHeader,
                    'Suggested Project-Id-Version:',
                    $want,
                ];
                $findings[] = new AuditFinding(
                    $this->id(),
                    'warning',
                    $rel,
                    $locale,
                    $msg,
                    $rawHeader,
                    $want,
                    null,
                    $details,
                    $summary
                );
            }
        }

        return $findings;
    }

    private static function extractVersionToken(string $headerValue): ?string
    {
        if (preg_match_all('/\d+\.\d+(?:\.\d+)?(?:[-+.a-zA-Z0-9]*)/', $headerValue, $m)) {
            $all = $m[0];
            if ($all !== []) {
                return (string) end($all);
            }
        }

        return null;
    }
}
