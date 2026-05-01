<?php

/**
 * Audit report with ANSI SGR colors on finding lines.
 *
 * @package PublishPress\Translations\Audit\Report
 */

namespace PublishPress\Translations\Audit\Report;

use PublishPress\Translations\Audit\AuditFinding;

final class AnsiAuditReportRenderer implements AuditReportRendererInterface
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
            $lines[] = self::colorizeFindingLine(AuditReportFindingFormatter::plainLine($f));
        }
        if ($findings === []) {
            $lines[] = '(no findings)';
        }

        return implode("\n", $lines) . "\n";
    }

    private static function colorizeFindingLine(string $line): string
    {
        if (strpos($line, '[error]') === 0) {
            return "\033[31m" . $line . "\033[0m";
        }
        if (strpos($line, '[warning]') === 0) {
            return "\033[33m" . $line . "\033[0m";
        }
        if (strpos($line, '[info]') === 0) {
            return "\033[34m" . $line . "\033[0m";
        }

        return $line;
    }
}
