<?php

/**
 * Shared one-line representation of an audit finding (txt / ansi).
 *
 * @package PublishPress\Translations\Audit\Report
 */

namespace PublishPress\Translations\Audit\Report;

use PublishPress\Translations\Audit\AuditFinding;

final class AuditReportFindingFormatter
{
    public static function plainLine(AuditFinding $f): string
    {
        $loc = $f->file !== '' ? $f->file : '(n/a)';
        if ($f->language !== '') {
            $loc .= ' [' . $f->language . ']';
        }
        $line = '[' . $f->severity . '] ' . $f->checkId . ' ' . $loc . ' — ' . $f->message;
        if ($f->actionTaken !== null && $f->actionTaken !== '') {
            $line .= ' [' . $f->actionTaken . ']';
        }

        return $line;
    }
}
