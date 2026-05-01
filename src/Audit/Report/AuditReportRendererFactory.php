<?php

/**
 * Resolves the renderer for a canonical {@see AuditReportFormat} id.
 *
 * @package PublishPress\Translations\Audit\Report
 */

namespace PublishPress\Translations\Audit\Report;

use InvalidArgumentException;
use PublishPress\Translations\Audit\AuditReportFormat;

final class AuditReportRendererFactory
{
    public static function forFormat(string $format): AuditReportRendererInterface
    {
        switch ($format) {
            case AuditReportFormat::HTML:
                return new HtmlAuditReportRenderer();
            case AuditReportFormat::ANSI:
                return new AnsiAuditReportRenderer();
            case AuditReportFormat::TXT:
                return new TxtAuditReportRenderer();
            default:
                throw new InvalidArgumentException("No audit report renderer for format '{$format}'");
        }
    }
}
