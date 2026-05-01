<?php

namespace PublishPress\Translations;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only text output, not HTML.

class Output
{
    /**
     * Indentation for output
     *
     * @var int
     */
    private $indent = '    ';

    public function width(): int
    {
        $cols = (int) getenv('COLUMNS');
        if ($cols > 0) {
            return min($cols, 200);
        }

        return 120;
    }

    public function separator(bool $double = false): void
    {
        $char = $double ? '═' : '─';

        echo str_repeat($char, $this->width());
        echo "\n";
    }

    /**
     * Full-width title band (double rules), matching PublishPress Builder CLI style.
     */
    public function banner(string $title): void
    {
        $this->separator(true);
        echo "\n" . $title . "\n\n";
        $this->separator(true);
    }

    /**
     * @deprecated Use banner()
     */
    public function title(string $title): void
    {
        $this->banner($title);
    }

    public function versionLine(string $label, string $version): void
    {
        echo $label . ': ' . $version . "\n";
    }

    public function blankLine(): void
    {
        echo "\n";
    }

    public function line(string $message = ''): void
    {
        echo $message . "\n";
    }

    public function sectionHeading(string $heading): void
    {
        echo $heading . "\n\n";
    }

    /**
     * Key/value row with bullet, aligned like Builder output.
     *
     * @param string $key   Short label (padded to fixed width)
     * @param string $value Display value
     */
    public function kv(string $key, string $value): void
    {
        $padded = str_pad($key, 7) . ' : ' . $value;
        echo $this->indent . '● ' . $padded . "\n";
    }

    /**
     * Bullet point with padding, aligned like Builder output.
     *
     * @param string $message Message to display
     */
    public function bullet(string $message): void
    {
        echo $this->indent . '● ' . $message . "\n";
    }

    public function phase(string $message): void
    {
        echo "\n🚀  " . $message . "\n";
        $this->blankLine();
    }

    public function step(string $message): void
    {
        echo '▶ ' . $message . "\n";
    }

    /**
     * @param string[] $lines
     */
    public function boxed(array $lines): void
    {
        if (count($lines) === 0) {
            return;
        }

        echo "\n┌──\n";
        foreach ($lines as $line) {
            echo '│ ' . $line . "\n";
        }
        echo "└──\n";
    }

    public function startBoxed(): void
    {
        echo "\n" . $this->indent . "┌──\n";
    }

    public function endBoxed(): void
    {
        echo $this->indent . "└──\n";
    }

    public function boxedLine(string $line): void
    {
        echo $this->indent . '│ ' . $line . "\n";
    }

    public function runtime(float $elapsedSeconds): void
    {
        $this->blankLine();
        $this->separator();
        $elapsedSeconds = max(0.0, $elapsedSeconds);
        if ($elapsedSeconds < 60) {
            $whole = (int) round($elapsedSeconds);
            $label = $whole === 1 ? 'second' : 'seconds';
            echo 'Runtime: ' . $whole . ' ' . $label . "\n";
            return;
        }

        $minutes = (int) floor($elapsedSeconds / 60);
        $seconds = (int) round($elapsedSeconds - ($minutes * 60));
        echo 'Runtime: ' . $minutes . ' min ' . $seconds . " s\n";
    }

    public function executedSuccessfully(): void
    {
        echo "\n🎉  Executed successfully!\n";
    }

    public function finishedWithErrors(): void
    {
        echo "\n\033[31mFinished with errors.\033[0m\n";
    }

    public function error(string $message, int $exitCode = 1): void
    {
        echo "\033[31m" . $message . "\033[0m\n";
        exit($exitCode);
    }

    public function warning(string $message): void
    {
        echo "\033[33m" . $message . "\033[0m\n";
    }

    public function success(string $message): void
    {
        echo "\033[32m" . $message . "\033[0m\n";
    }

    public function info(string $message): void
    {
        echo "\033[34m" . $message . "\033[0m\n";
    }

    public function debug(string $message): void
    {
        echo "\033[30m" . $message . "\033[0m\n";
    }
}

// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
