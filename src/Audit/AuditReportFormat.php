<?php

/**
 * Supported audit report output formats (--audit-report-format).
 *
 * - txt: Plain UTF-8 text, no terminal escape sequences (CI logs, grep, artifacts).
 * - ansi: Same content as txt with SGR color codes (local terminal or less -R).
 * - html: Single-file HTML table for browsers / shared review.
 *
 * @package PublishPress\Translations\Audit
 */

namespace PublishPress\Translations\Audit;

use InvalidArgumentException;

final class AuditReportFormat
{
    public const TXT = 'txt';

    public const ANSI = 'ansi';

    public const HTML = 'html';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [self::TXT, self::ANSI, self::HTML];
    }

    public static function isValid(string $id): bool
    {
        return in_array(self::normalize($id), self::all(), true);
    }

    /**
     * Map CLI aliases to canonical ids (e.g. plain → txt).
     */
    public static function normalize(string $raw): string
    {
        $id = strtolower(trim($raw));
        if ($id === 'plain' || $id === 'text') {
            return self::TXT;
        }

        return $id;
    }

    /**
     * @param string[] $rawIds
     *
     * @return string[] canonical unique formats, order preserved
     */
    public static function parseList(array $rawIds): array
    {
        $out = [];
        foreach ($rawIds as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            $id = self::normalize($raw);
            if (!self::isValid($id)) {
                throw new InvalidArgumentException(
                    "Invalid --audit-report-format '{$raw}'. Allowed: " . implode(', ', self::all())
                    . ' (aliases: plain, text → txt)'
                );
            }
            if (!in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }
}
