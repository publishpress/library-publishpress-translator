<?php

/**
 * Single audit verification (strategy).
 *
 * @package PublishPress\Translations\Audit
 */

namespace PublishPress\Translations\Audit;

interface AuditCheckInterface
{
    public function id(): string;

    public function title(): string;

    /**
     * @return AuditFinding[]
     */
    public function run(AuditContext $ctx): array;
}
