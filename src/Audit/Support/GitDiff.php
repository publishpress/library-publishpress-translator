<?php

/**
 * Thin git wrapper for audit (changed files, blob read).
 *
 * @package PublishPress\Translations\Audit\Support
 */

namespace PublishPress\Translations\Audit\Support;

final class GitDiff
{
    /** @var string */
    private $pluginRoot;

    public function __construct(string $pluginRoot)
    {
        $this->pluginRoot = $pluginRoot;
    }

    public static function isAvailable(string $pluginRoot): bool
    {
        $git = self::gitBinary();
        if ($git === null) {
            return false;
        }

        $cwd = $pluginRoot;
        $out = [];
        $code = 0;
        @exec(escapeshellarg($git) . ' --version 2>/dev/null', $out, $code);
        if ($code !== 0) {
            return false;
        }

        @exec(
            escapeshellarg($git) . ' -C ' . escapeshellarg($cwd) . ' rev-parse --is-inside-work-tree 2>/dev/null',
            $out2,
            $code2
        );

        return $code2 === 0 && isset($out2[0]) && trim($out2[0]) === 'true';
    }

    /**
     * Relative paths (from repo root) of changed .po under languages/.
     *
     * @return string[]
     */
    public function changedPoFiles(string $base = 'HEAD'): array
    {
        $git = self::gitBinary();
        if ($git === null || !self::isAvailable($this->pluginRoot)) {
            return [];
        }

        $cmd = escapeshellarg($git)
            . ' -C ' . escapeshellarg($this->pluginRoot)
            . ' diff --name-only ' . escapeshellarg($base) . ' -- languages/*.po 2>/dev/null';

        $out = [];
        @exec($cmd, $out, $code);
        if ($code !== 0) {
            return [];
        }

        $paths = [];
        foreach ($out as $line) {
            $line = trim($line);
            if ($line !== '' && substr($line, -3) === '.po') {
                $paths[] = $line;
            }
        }

        return array_values(array_unique($paths));
    }

    public function fileAtRef(string $relativePath, string $ref): ?string
    {
        $git = self::gitBinary();
        if ($git === null || !self::isAvailable($this->pluginRoot)) {
            return null;
        }

        $pathArg = $ref . ':' . $relativePath;
        $cmd      = escapeshellarg($git)
            . ' -C ' . escapeshellarg($this->pluginRoot)
            . ' show ' . escapeshellarg($pathArg) . ' 2>/dev/null';

        $out = shell_exec($cmd);
        if ($out === false || $out === null || $out === '') {
            return null;
        }

        return $out;
    }

    private static function gitBinary(): ?string
    {
        $which = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'where git 2>nul' : 'command -v git 2>/dev/null';
        $path  = shell_exec($which);
        if ($path === false || $path === null) {
            return null;
        }

        $path = trim($path);
        if ($path === '') {
            return null;
        }

        $lines = explode("\n", $path);

        return trim($lines[0]);
    }
}
