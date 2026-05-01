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

    /**
     * Check ids that ran in this audit (--audit-only order).
     *
     * @var string[]
     */
    private $enabledCheckIds;

    /**
     * @param string[] $enabledCheckIds CheckId values
     */
    public function __construct(
        string $pluginDisplayName,
        ?string $pluginVersion,
        bool $passed,
        array $enabledCheckIds = []
    ) {
        $this->pluginDisplayName = $pluginDisplayName;
        $this->pluginVersion     = $pluginVersion;
        $this->passed            = $passed;
        $this->enabledCheckIds    = array_values($enabledCheckIds);
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

    /**
     * @return string[]
     */
    public function enabledCheckIds(): array
    {
        return $this->enabledCheckIds;
    }
}
