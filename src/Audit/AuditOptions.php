<?php

/**
 * Immutable CLI options for translation audit.
 *
 * @package PublishPress\Translations\Audit
 */

namespace PublishPress\Translations\Audit;

use InvalidArgumentException;

final class AuditOptions
{
    /** @var string */
    private $mode;

    /** @var float */
    private $maxCostUsd;

    /** @var string[] */
    private $only;

    /** @var bool */
    private $strictPo;

    /**
     * @var string[]
     */
    private $reportFormats;

    /** @var string|null */
    private $reportDir;

    /** @var string[] */
    private $sourceExcludePaths;

    /**
     * @param string[] $only
     * @param string[] $reportFormats AuditReportFormat::* values
     * @param string[] $sourceExcludePaths
     */
    private function __construct(
        string $mode,
        float $maxCostUsd,
        array $only,
        bool $strictPo,
        array $reportFormats,
        ?string $reportDir,
        array $sourceExcludePaths
    ) {
        $this->mode                = $mode;
        $this->maxCostUsd          = $maxCostUsd;
        $this->only                = $only;
        $this->strictPo            = $strictPo;
        $this->reportFormats       = $reportFormats;
        $this->reportDir           = $reportDir;
        $this->sourceExcludePaths  = $sourceExcludePaths;
    }

    public static function defaults(): self
    {
        return new self('interactive', 5.0, CheckId::all(), false, [], null, ['dev-workspace-cache', 'vendor', 'lib/vendor', 'node_modules']);
    }

    public function withMode(string $mode): self
    {
        $allowed = ['interactive', 'allow-edit', 'report'];
        if (!in_array($mode, $allowed, true)) {
            throw new InvalidArgumentException(
                "Invalid audit mode '{$mode}'. Allowed: " . implode(', ', $allowed)
            );
        }

        return new self($mode, $this->maxCostUsd, $this->only, $this->strictPo, $this->reportFormats, $this->reportDir, $this->sourceExcludePaths);
    }

    public function withMaxCost(float $usd): self
    {
        if ($usd < 0) {
            throw new InvalidArgumentException('audit max cost must be >= 0');
        }

        return new self($this->mode, $usd, $this->only, $this->strictPo, $this->reportFormats, $this->reportDir, $this->sourceExcludePaths);
    }

    /**
     * @param string[] $checks CheckId values
     */
    public function withOnly(array $checks): self
    {
        foreach ($checks as $c) {
            if (!CheckId::isValid($c)) {
                throw new InvalidArgumentException(
                    "Invalid --audit-only check '{$c}'. Allowed: " . implode(', ', CheckId::all())
                );
            }
        }

        return new self(
            $this->mode,
            $this->maxCostUsd,
            array_values(array_unique($checks)),
            $this->strictPo,
            $this->reportFormats,
            $this->reportDir,
            $this->sourceExcludePaths
        );
    }

    public function withStrictPo(bool $strict): self
    {
        return new self($this->mode, $this->maxCostUsd, $this->only, $strict, $this->reportFormats, $this->reportDir, $this->sourceExcludePaths);
    }

    /**
     * @param string[] $formats Raw CLI tokens (comma-split); validated canonical ids
     */
    public function withReportFormats(array $formats): self
    {
        $canonical = AuditReportFormat::parseList($formats);

        return new self($this->mode, $this->maxCostUsd, $this->only, $this->strictPo, $canonical, $this->reportDir, $this->sourceExcludePaths);
    }

    public function withReportDir(?string $dir): self
    {
        if ($dir !== null) {
            $dir = trim($dir);
            if ($dir === '') {
                throw new InvalidArgumentException('audit report directory must not be empty when set');
            }
        }

        return new self($this->mode, $this->maxCostUsd, $this->only, $this->strictPo, $this->reportFormats, $dir, $this->sourceExcludePaths);
    }

    /**
     * @param string[] $paths Path substrings; any source file whose normalized path contains one of these is skipped.
     */
    public function withSourceExcludePaths(array $paths): self
    {
        return new self($this->mode, $this->maxCostUsd, $this->only, $this->strictPo, $this->reportFormats, $this->reportDir, array_values($paths));
    }

    /**
     * @return string[]
     */
    public function sourceExcludePaths(): array
    {
        return $this->sourceExcludePaths;
    }

    /**
     * Non-TTY + interactive → force report (no prompts possible).
     */
    public function resolveForRuntime(): self
    {
        if ($this->mode !== 'interactive') {
            return $this;
        }

        $isCi = getenv('CI') === 'true' || getenv('CI') === '1';
        if ($isCi || (defined('STDIN') && function_exists('stream_isatty') && !stream_isatty(STDIN))) {
            return $this->withMode('report');
        }

        return $this;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function maxCostUsd(): float
    {
        return $this->maxCostUsd;
    }

    /**
     * @return string[]
     */
    public function only(): array
    {
        return $this->only;
    }

    public function strictPo(): bool
    {
        return $this->strictPo;
    }

    /**
     * @return string[]
     */
    public function reportFormats(): array
    {
        return $this->reportFormats;
    }

    public function reportDir(): ?string
    {
        return $this->reportDir;
    }

    public function usesReportFiles(): bool
    {
        return $this->reportFormats !== [];
    }

    public function reportOutputDir(string $pluginRoot): string
    {
        if ($this->reportDir !== null && $this->reportDir !== '') {
            return rtrim(str_replace('\\', '/', $this->reportDir), '/');
        }

        return rtrim(str_replace('\\', '/', $pluginRoot), '/');
    }

    public function shouldRun(string $checkId): bool
    {
        return in_array($checkId, $this->only, true);
    }

    public function isInteractive(): bool
    {
        return $this->mode === 'interactive';
    }

    public function isAllowEdit(): bool
    {
        return $this->mode === 'allow-edit';
    }

    public function isReportOnly(): bool
    {
        return $this->mode === 'report';
    }
}
