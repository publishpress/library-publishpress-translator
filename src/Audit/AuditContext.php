<?php

/**
 * Per-run audit context (paths, options, services).
 *
 * @package PublishPress\Translations\Audit
 */

namespace PublishPress\Translations\Audit;

use PublishPress\Translations\Output;

final class AuditContext
{
    /** @var string */
    private $pluginRoot;

    /** @var string */
    private $languagesDir;

    /** @var string[] */
    private $targetLanguages;

    /** @var Output */
    private $output;

    /** @var string|null */
    private $apiKey;

    /** @var string|null */
    private $pluginVersion;

    /** @var string */
    private $pluginDisplayName;

    /** @var AuditOptions */
    private $options;

    /** @var string */
    private $gitBase;

    /**
     * @param string[] $targetLanguages
     */
    public function __construct(
        string $pluginRoot,
        string $languagesDir,
        array $targetLanguages,
        Output $output,
        ?string $apiKey,
        ?string $pluginVersion,
        string $pluginDisplayName,
        AuditOptions $options,
        string $gitBase = 'HEAD'
    ) {
        $this->pluginRoot         = $pluginRoot;
        $this->languagesDir       = $languagesDir;
        $this->targetLanguages    = $targetLanguages;
        $this->output             = $output;
        $this->apiKey             = $apiKey;
        $this->pluginVersion      = $pluginVersion;
        $this->pluginDisplayName  = $pluginDisplayName;
        $this->options            = $options;
        $this->gitBase            = $gitBase;
    }

    public function pluginRoot(): string
    {
        return $this->pluginRoot;
    }

    public function languagesDir(): string
    {
        return $this->languagesDir;
    }

    /**
     * @return string[]
     */
    public function targetLanguages(): array
    {
        return $this->targetLanguages;
    }

    public function output(): Output
    {
        return $this->output;
    }

    public function apiKey(): ?string
    {
        return $this->apiKey;
    }

    public function pluginVersion(): ?string
    {
        return $this->pluginVersion;
    }

    public function pluginDisplayName(): string
    {
        return $this->pluginDisplayName;
    }

    public function options(): AuditOptions
    {
        return $this->options;
    }

    public function gitBase(): string
    {
        return $this->gitBase;
    }

    public function isInteractive(): bool
    {
        return $this->options->isInteractive();
    }

    public function isAllowEdit(): bool
    {
        return $this->options->isAllowEdit();
    }

    public function isReportOnly(): bool
    {
        return $this->options->isReportOnly();
    }
}
