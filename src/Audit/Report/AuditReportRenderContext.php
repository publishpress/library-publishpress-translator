<?php

/**
 * Metadata passed into audit report renderers.
 *
 * @package PublishPress\Translations\Audit\Report
 */

namespace PublishPress\Translations\Audit\Report;

final class AuditReportRenderContext
{
    /** @var string */
    private $pluginDisplayName;

    /** @var string|null */
    private $pluginVersion;

    /** @var bool */
    private $passed;

    public function __construct(string $pluginDisplayName, ?string $pluginVersion, bool $passed)
    {
        $this->pluginDisplayName = $pluginDisplayName;
        $this->pluginVersion     = $pluginVersion;
        $this->passed            = $passed;
    }

    public function pluginDisplayName(): string
    {
        return $this->pluginDisplayName;
    }

    public function pluginVersion(): ?string
    {
        return $this->pluginVersion;
    }

    public function passed(): bool
    {
        return $this->passed;
    }
}
