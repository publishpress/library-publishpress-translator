<?php

/**
 * Plain UTF-8 audit report (no terminal escape sequences).
 *
 * @package PublishPress\Translations\Audit\Report
 */

namespace PublishPress\Translations\Audit\Report;

use PublishPress\Translations\Audit\AuditFinding;

final class TxtAuditReportRenderer implements AuditReportRendererInterface
{
    /**
     * @param AuditFinding[] $findings
     */
    public function render(array $findings, AuditReportRenderContext $ctx): string
    {
        $lines   = [];
        $lines[] = 'Translation audit report';
        $lines[] = 'Plugin: ' . $ctx->pluginDisplayName();
        $ver = $ctx->pluginVersion();
        $lines[] = 'Version: ' . ($ver !== null && $ver !== '' ? $ver : 'n/a');
        $lines[] = 'Generated: ' . gmdate('Y-m-d\TH:i:s\Z');
        $lines[] = 'Result: ' . ($ctx->passed() ? 'passed' : 'failed');
        $lines[] = 'Findings: ' . count($findings);
        $lines[] = str_repeat('-', 72);
        foreach ($findings as $f) {
            $lines[] = AuditReportFindingFormatter::plainLine($f);
        }
        if ($findings === []) {
            $lines[] = '(no findings)';
        }

        return implode("\n", $lines) . "\n";
    }
}
