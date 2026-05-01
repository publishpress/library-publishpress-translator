<?php

/**
 * Single-file HTML table audit report.
 *
 * @package PublishPress\Translations\Audit\Report
 */

namespace PublishPress\Translations\Audit\Report;

use PublishPress\Translations\Audit\AuditFinding;

final class HtmlAuditReportRenderer implements AuditReportRendererInterface
{
    /**
     * @param AuditFinding[] $findings
     */
    public function render(array $findings, AuditReportRenderContext $ctx): string
    {
        $escName = htmlspecialchars($ctx->pluginDisplayName(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $verRaw  = $ctx->pluginVersion();
        $ver     = $verRaw !== null && $verRaw !== ''
            ? htmlspecialchars($verRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : 'n/a';
        $status  = $ctx->passed() ? 'passed' : 'failed';
        $rows    = '';
        foreach ($findings as $f) {
            $rows .= '<tr>'
                . '<td>' . htmlspecialchars($f->severity, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($f->checkId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($f->file, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($f->language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($f->message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $f->actionTaken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
                . "</tr>\n";
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6">(no findings)</td></tr>' . "\n";
        }

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<title>Translation audit — ' . $escName . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;margin:1.5rem;line-height:1.4}'
            . 'table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:.4rem .6rem;text-align:left}'
            . 'th{background:#f4f4f4}.meta{margin-bottom:1rem}</style></head><body>'
            . '<h1>Translation audit</h1>'
            . '<div class="meta"><strong>Plugin:</strong> ' . $escName . '<br>'
            . '<strong>Version:</strong> ' . $ver . '<br>'
            . '<strong>Generated (UTC):</strong> ' . htmlspecialchars(gmdate('Y-m-d H:i:s'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '<br>'
            . '<strong>Result:</strong> ' . htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '<br>'
            . '<strong>Findings:</strong> ' . count($findings) . '</div>'
            . '<table><thead><tr><th>Severity</th><th>Check</th><th>File</th><th>Language</th><th>Message</th><th>Action</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></body></html>' . "\n";
    }
}
