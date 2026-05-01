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
     * @param string[] $only
     */
    private function __construct(string $mode, float $maxCostUsd, array $only, bool $strictPo)
    {
        $this->mode       = $mode;
        $this->maxCostUsd = $maxCostUsd;
        $this->only       = $only;
        $this->strictPo   = $strictPo;
    }

    public static function defaults(): self
    {
        return new self('interactive', 5.0, CheckId::all(), false);
    }

    public function withMode(string $mode): self
    {
        $allowed = ['interactive', 'allow-edit', 'report'];
        if (!in_array($mode, $allowed, true)) {
            throw new InvalidArgumentException(
                "Invalid audit mode '{$mode}'. Allowed: " . implode(', ', $allowed)
            );
        }

        return new self($mode, $this->maxCostUsd, $this->only, $this->strictPo);
    }

    public function withMaxCost(float $usd): self
    {
        if ($usd < 0) {
            throw new InvalidArgumentException('audit max cost must be >= 0');
        }

        return new self($this->mode, $usd, $this->only, $this->strictPo);
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

        return new self($this->mode, $this->maxCostUsd, array_values(array_unique($checks)), $this->strictPo);
    }

    public function withStrictPo(bool $strict): self
    {
        return new self($this->mode, $this->maxCostUsd, $this->only, $strict);
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
