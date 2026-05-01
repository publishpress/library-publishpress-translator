<?php

/**
 * HTML audit report: overview + per-check tabs.
 *
 * @package PublishPress\Translations\Audit\Report
 */

namespace PublishPress\Translations\Audit\Report;

use PublishPress\Translations\Audit\AuditFinding;
use PublishPress\Translations\Audit\CheckId;

final class HtmlAuditReportRenderer implements AuditReportRendererInterface
{
    /**
     * @param AuditFinding[] $findings
     */
    public function render(array $findings, AuditReportRenderContext $ctx): string
    {
        $escName = self::h($ctx->pluginDisplayName());
        $verRaw  = $ctx->pluginVersion();
        $ver     = $verRaw !== null && $verRaw !== '' ? self::h($verRaw) : 'n/a';
        $status  = $ctx->passed() ? 'passed' : 'failed';
        $statusC = $ctx->passed() ? 'ok' : 'fail';

        $byCheck = [];
        foreach ($findings as $f) {
            $byCheck[$f->checkId][] = $f;
        }

        $checkOrder = $ctx->enabledCheckIds();
        if ($checkOrder === []) {
            $checkOrder = CheckId::all();
        }

        $overviewBody = self::buildOverviewRows($checkOrder, $byCheck);
        $tabBar       = '<button type="button" class="tab-btn active" role="tab" aria-selected="true" data-panel="panel-overview">Overview</button>';
        $panels       = '<section id="panel-overview" class="tab-panel active" role="tabpanel">'
            . '<h2>Overview</h2>'
            . '<p><strong>Overall:</strong> <span class="badge ' . self::h($statusC) . '">' . self::h($status) . '</span>'
            . ' — <strong>Total findings:</strong> ' . count($findings) . '</p>'
            . '<table class="overview-table"><thead><tr><th>Check</th><th>Errors</th><th>Warnings</th><th>Info</th><th>Status</th></tr></thead>'
            . '<tbody>' . $overviewBody . '</tbody></table>'
            . '</section>';

        foreach ($checkOrder as $cid) {
            $panelId = 'panel-check-' . self::panelIdSuffix($cid);
            $label   = self::h(CheckId::label($cid));
            $tabBar .= '<button type="button" class="tab-btn" role="tab" aria-selected="false" data-panel="' . self::h($panelId) . '">' . $label . '</button>';

            $list = $byCheck[$cid] ?? [];
            if ($list === []) {
                $body = '<p class="all-clear">Everything passed — no issues reported for this check.</p>';
            } else {
                $rows = '';
                foreach ($list as $f) {
                    $rows .= self::findingRow($f);
                }
                $body = '<table class="findings-table"><thead><tr><th>Severity</th><th>File</th><th>Language</th><th>Issue</th><th>Summary</th></tr></thead>'
                    . '<tbody>' . $rows . '</tbody></table>';
            }

            $panels .= '<section id="' . self::h($panelId) . '" class="tab-panel" role="tabpanel">'
                . '<h2>' . $label . '</h2>' . $body . '</section>';
        }

        $css = 'body{font-family:system-ui,sans-serif;margin:1.5rem;line-height:1.4}'
            . 'table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:.4rem .6rem;text-align:left;vertical-align:top}'
            . 'th{background:#f4f4f4}.meta{margin-bottom:1rem}'
            . '.detail-block{margin-top:.35rem;display:flex;flex-direction:column;gap:.35rem}'
            . 'pre.detail{margin:0;white-space:pre-wrap;word-break:break-word;font-size:.9rem;background:#f9f9f9;padding:.5rem;border:1px solid #e0e0e0}'
            . '.tab-shell{margin-top:1.25rem}.tab-bar{display:flex;flex-wrap:wrap;gap:.25rem;border-bottom:1px solid #ccc;margin-bottom:0}'
            . '.tab-btn{padding:.5rem .85rem;background:#eee;border:1px solid #ccc;border-bottom:none;cursor:pointer;font:inherit;border-radius:4px 4px 0 0}'
            . '.tab-btn.active{background:#fff;font-weight:600;position:relative;bottom:-1px}'
            . '.tab-panel{display:none;padding:1rem 0}.tab-panel.active{display:block}'
            . '.overview-table,.findings-table{margin-top:.5rem}'
            . '.badge.ok{color:#0a6b0a;font-weight:600}.badge.warn{color:#a65a00;font-weight:600}.badge.fail{color:#b00000;font-weight:600}'
            . '.all-clear{padding:1rem;background:#f4fff4;border:1px solid #cec;border-radius:4px;margin:.5rem 0}';

        $script = '(function(){var r=document.querySelector(".tab-shell");if(!r)return;'
            . 'r.querySelectorAll(".tab-btn").forEach(function(btn){'
            . 'btn.addEventListener("click",function(){'
            . 'var id=this.getAttribute("data-panel");'
            . 'r.querySelectorAll(".tab-btn").forEach(function(b){b.classList.remove("active");b.setAttribute("aria-selected","false");});'
            . 'r.querySelectorAll(".tab-panel").forEach(function(p){p.classList.remove("active");});'
            . 'this.classList.add("active");this.setAttribute("aria-selected","true");'
            . 'var p=document.getElementById(id);if(p)p.classList.add("active");'
            . '});});})();';

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<title>Translation audit — ' . $escName . '</title>'
            . '<style>' . $css . '</style></head><body>'
            . '<h1>Translation audit</h1>'
            . '<div class="meta"><strong>Plugin:</strong> ' . $escName . '<br>'
            . '<strong>Version:</strong> ' . $ver . '<br>'
            . '<strong>Generated (UTC):</strong> ' . self::h(gmdate('Y-m-d H:i:s')) . '<br>'
            . '<strong>Overall result:</strong> <span class="badge ' . self::h($statusC) . '">' . self::h($status) . '</span></div>'
            . '<div class="tab-shell">'
            . '<div class="tab-bar" role="tablist">' . $tabBar . '</div>'
            . $panels
            . '</div>'
            . '<script>' . $script . '</script>'
            . '</body></html>' . "\n";
    }

    /**
     * @param string[]                     $checkOrder
     * @param array<string,AuditFinding[]> $byCheck
     */
    private static function buildOverviewRows(array $checkOrder, array $byCheck): string
    {
        $html = '';
        foreach ($checkOrder as $cid) {
            $list = $byCheck[$cid] ?? [];
            $s     = self::countSeverities($list);
            $errs  = $s['error'];
            $warns = $s['warning'];
            $infos = $s['info'];

            if ($errs > 0) {
                $badge = '<span class="badge fail">Errors</span>';
            } elseif ($warns > 0) {
                $badge = '<span class="badge warn">Warnings</span>';
            } else {
                $badge = '<span class="badge ok">Everything passed</span>';
            }

            $html .= '<tr>'
                . '<td>' . self::h(CheckId::label($cid)) . '</td>'
                . '<td>' . $errs . '</td>'
                . '<td>' . $warns . '</td>'
                . '<td>' . $infos . '</td>'
                . '<td>' . $badge . '</td>'
                . "</tr>\n";
        }

        if ($checkOrder === []) {
            $html .= '<tr><td colspan="5">No checks in scope.</td></tr>';
        }

        return $html;
    }

    /**
     * @param AuditFinding[] $list
     *
     * @return array{error:int,warning:int,info:int}
     */
    private static function countSeverities(array $list): array
    {
        $e = 0;
        $w = 0;
        $i = 0;
        foreach ($list as $f) {
            if ($f->severity === 'error') {
                ++$e;
            } elseif ($f->severity === 'warning') {
                ++$w;
            } else {
                ++$i;
            }
        }

        return ['error' => $e, 'warning' => $w, 'info' => $i];
    }

    private static function panelIdSuffix(string $checkId): string
    {
        return preg_replace('/[^a-z0-9-]+/i', '-', $checkId) ?: 'check';
    }

    private static function findingRow(AuditFinding $f): string
    {
        $msgCell = self::h($f->reportHeadline());
        $details = $f->reportDetails();
        if ($details !== []) {
            $msgCell .= '<div class="detail-block">';
            foreach ($details as $line) {
                $msgCell .= '<pre class="detail">' . self::h($line) . '</pre>';
            }
            $msgCell .= '</div>';
        }

        return '<tr>'
            . '<td>' . self::h($f->severity) . '</td>'
            . '<td>' . self::h($f->file) . '</td>'
            . '<td>' . self::h($f->language) . '</td>'
            . '<td><code>' . self::h($f->resolvedIssueSlug()) . '</code></td>'
            . '<td>' . $msgCell . '</td>'
            . "</tr>\n";
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
