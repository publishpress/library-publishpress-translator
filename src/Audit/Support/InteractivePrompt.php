<?php

/**
 * STDIN prompts for audit (TTY only; Auditor degrades non-TTY to report).
 *
 * @package PublishPress\Translations\Audit\Support
 */

namespace PublishPress\Translations\Audit\Support;

final class InteractivePrompt
{
    /** @var bool */
    private $acceptAllRemaining = false;

    public function resetAcceptAll(): void
    {
        $this->acceptAllRemaining = false;
    }

    /**
     * @return string one of keep|revert|quit
     */
    public function askDiffAction(string $summary): string
    {
        if ($this->acceptAllRemaining) {
            return 'revert';
        }

        fwrite(STDOUT, $summary . "\n[k]eep  [r]evert  [v]iew  [a]ccept-all-revert  [q]uit check: ");

        $line = fgets(STDIN);
        if ($line === false) {
            return 'keep';
        }

        $c = strtolower(trim($line));
        if ($c === 'r' || $c === 'revert') {
            return 'revert';
        }
        if ($c === 'q' || $c === 'quit') {
            return 'quit';
        }
        if ($c === 'a' || $c === 'accept-all-revert') {
            $this->acceptAllRemaining = true;

            return 'revert';
        }
        if ($c === 'v' || $c === 'view') {
            return 'view';
        }

        return 'keep';
    }
}
