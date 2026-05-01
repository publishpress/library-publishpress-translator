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

    public function __construct(
        string $checkId,
        string $severity,
        string $file,
        string $language,
        string $message,
        ?string $before = null,
        ?string $after = null,
        ?string $actionTaken = null
    ) {
        $this->checkId     = $checkId;
        $this->severity    = $severity;
        $this->file        = $file;
        $this->language    = $language;
        $this->message     = $message;
        $this->before      = $before;
        $this->after       = $after;
        $this->actionTaken = $actionTaken;
    }
}
