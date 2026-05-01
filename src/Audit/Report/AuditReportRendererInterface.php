<?php

/**
 * Renders audit findings to a string for one output format.
 *
 * @package PublishPress\Translations\Audit\Report
 */

namespace PublishPress\Translations\Audit\Report;

use PublishPress\Translations\Audit\AuditFinding;

interface AuditReportRendererInterface
{
    /**
     * @param AuditFinding[] $findings
     */
    public function render(array $findings, AuditReportRenderContext $ctx): string;
}
