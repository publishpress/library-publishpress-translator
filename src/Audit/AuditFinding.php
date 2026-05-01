<?php

/**
 * One row of audit output (machine + human readable).
 *
 * @package PublishPress\Translations\Audit
 */

namespace PublishPress\Translations\Audit;

final class AuditFinding
{
    /** @var string */
    public $checkId;

    /** @var string info|warning|error */
    public $severity;

    /** @var string */
    public $file;

    /** @var string */
    public $language;

    /** @var string */
    public $message;

    /** @var string|null */
    public $before;

    /** @var string|null */
    public $after;

    /** @var string|null */
    public $actionTaken;

    /**
     * Short headline for file reports (no embedded samples). CLI {@see $message} may stay verbose.
     *
     * @var string|null
     */
    public $reportSummary;

    /**
     * Full strings / blocks for file reports only (e.g. every msgid, every orphan key).
     *
     * @var string[]|null
     */
    public $reportDetailLines;

    /**
     * Machine-readable issue id (e.g. empty-translation, orphan-msgid) for reports and filtering.
     *
     * @var string|null
     */
    public $issueSlug;

    /**
     * @param string[]|null $reportDetailLines
     */
    public function __construct(
        string $checkId,
        string $severity,
        string $file,
        string $language,
        string $message,
        ?string $before = null,
        ?string $after = null,
        ?string $actionTaken = null,
        ?array $reportDetailLines = null,
        ?string $reportSummary = null,
        ?string $issueSlug = null
    ) {
        $this->checkId            = $checkId;
        $this->severity           = $severity;
        $this->file               = $file;
        $this->language           = $language;
        $this->message            = $message;
        $this->before             = $before;
        $this->after              = $after;
        $this->actionTaken        = $actionTaken;
        $this->reportDetailLines  = $reportDetailLines;
        $this->reportSummary      = $reportSummary;
        $this->issueSlug          = $issueSlug;
    }

    /**
     * Non-empty slug for tables and plain-text reports.
     */
    public function resolvedIssueSlug(): string
    {
        if ($this->issueSlug !== null && $this->issueSlug !== '') {
            return $this->issueSlug;
        }

        return IssueSlug::fallbackForCheckId($this->checkId);
    }

    /**
     * One-line text for report tables / headers (no CLI-only sample suffix).
     */
    public function reportHeadline(): string
    {
        if ($this->reportSummary !== null && $this->reportSummary !== '') {
            return $this->reportSummary;
        }

        return $this->message;
    }

    /**
     * @return string[]
     */
    public function reportDetails(): array
    {
        return $this->reportDetailLines ?? [];
    }
}
