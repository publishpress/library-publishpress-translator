<?php

/**
 * Formatting audit findings for CLI vs file reports.
 *
 * @package PublishPress\Translations\Audit\Report
 */

namespace PublishPress\Translations\Audit\Report;

use PublishPress\Translations\Audit\AuditFinding;

final class AuditReportFindingFormatter
{
    /**
     * One line for streaming CLI (full {@see AuditFinding::$message} including samples).
     */
    public static function plainLine(AuditFinding $f): string
    {
        return self::formatHeadlineLine($f, $f->message);
    }

    /**
     * Headline for file reports: {@see AuditFinding::reportHeadline()} (summary without samples).
     */
    public static function plainLineForReport(AuditFinding $f): string
    {
        return self::formatHeadlineLine($f, $f->reportHeadline());
    }

    /**
     * @param string[] $lines output buffer
     */
    public static function appendFindingBlockForReport(array &$lines, AuditFinding $f): void
    {
        $lines[] = self::plainLineForReport($f);
        foreach ($f->reportDetails() as $detailLine) {
            $lines[] = '  ' . $detailLine;
        }
    }

    private static function formatHeadlineLine(AuditFinding $f, string $messageBody): string
    {
        $loc = $f->file !== '' ? $f->file : '(n/a)';
        if ($f->language !== '') {
            $loc .= ' [' . $f->language . ']';
        }
        $line = '[' . $f->severity . '] ' . $f->resolvedIssueSlug() . ' ' . $loc . ' — ' . $messageBody;
        if ($f->actionTaken !== null && $f->actionTaken !== '') {
            $line .= ' [' . $f->actionTaken . ']';
        }

        return $line;
    }

    public static function colorizeSeverityLine(string $line): string
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

    /**
     * Dim continuation lines (detail strings under a finding).
     */
    public static function colorizeDetailLine(string $line): string
    {
        return "\033[2m" . $line . "\033[0m";
    }
}
