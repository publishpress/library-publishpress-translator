<?php

/**
 * Project-Id-Version header vs plugin Version header (optional header update).
 *
 * @package PublishPress\Translations\Audit\Checks
 */

namespace PublishPress\Translations\Audit\Checks;

use PublishPress\Translations\Audit\AuditCheckInterface;
use PublishPress\Translations\Audit\AuditContext;
use PublishPress\Translations\Audit\AuditFinding;
use PublishPress\Translations\Audit\CheckId;
use PublishPress\Translations\Audit\Support\InteractivePrompt;
use PublishPress\Translations\Audit\Support\PoEntrySplicer;
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

        $strict  = $ctx->options()->strictPo();
        $dir     = $ctx->languagesDir();
        $prompt  = new InteractivePrompt();
        $want    = trim($ctx->pluginDisplayName()) . ' ' . trim($pluginV);

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
                    $findings[] = new AuditFinding(
                        $this->id(),
                        'warning',
                        $rel,
                        $locale,
                        'Project-Id-Version header missing (manual edit or regenerate .po).',
                        null,
                        $want,
                        'none (report mode)'
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
                        'PO claims newer version (' . $poVer . ') than plugin (' . $pluginV . ') — not auto-fixed.',
                        $rawHeader,
                        null,
                        null
                    );
                    continue;
                }

                $msg = 'Project-Id-Version outdated (' . $poVer . ' < ' . $pluginV . ').';
                if ($ctx->isReportOnly()) {
                    $findings[] = new AuditFinding(
                        $this->id(),
                        'warning',
                        $rel,
                        $locale,
                        $msg,
                        $rawHeader,
                        $want,
                        'none (report mode)'
                    );
                    continue;
                }

                $do = $ctx->isAllowEdit();
                if ($ctx->isInteractive()) {
                    $a = $prompt->askHeaderAction($msg . ' Suggested: ' . $want);
                    if ($a === 'quit') {
                        $findings[] = new AuditFinding(
                            $this->id(),
                            'warning',
                            $rel,
                            $locale,
                            'User quit check.',
                            $rawHeader,
                            $want,
                            'quit'
                        );

                        return $findings;
                    }
                    $do = $a === 'update';
                }

                if (!$do) {
                    $findings[] = new AuditFinding(
                        $this->id(),
                        'info',
                        $rel,
                        $locale,
                        'Skipped header update.',
                        $rawHeader,
                        $want,
                        'kept'
                    );
                    continue;
                }

                try {
                    PoEntrySplicer::replaceProjectIdVersion($file, $want);
                    $findings[] = new AuditFinding(
                        $this->id(),
                        'info',
                        $rel,
                        $locale,
                        'Updated Project-Id-Version.',
                        $rawHeader,
                        $want,
                        'edited-header'
                    );
                } catch (\Throwable $e) {
                    $findings[] = new AuditFinding(
                        $this->id(),
                        'warning',
                        $rel,
                        $locale,
                        'Header update failed: ' . $e->getMessage(),
                        $rawHeader,
                        $want,
                        null
                    );
                }
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
