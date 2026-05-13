<?php

/**
 * Verifies that every statically-extractable i18n string in PHP and JS/JSX source files is present in the POT.
 *
 * @package PublishPress\Translations\Audit\Checks
 * @author PublishPress
 * @copyright Copyright (c) 2026, PublishPress
 * @license GPL v2 or later
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PublishPress\Translations\Audit\Checks;

use PublishPress\Translations\Audit\AuditCheckInterface;
use PublishPress\Translations\Audit\AuditContext;
use PublishPress\Translations\Audit\AuditFinding;
use PublishPress\Translations\Audit\CheckId;
use PublishPress\Translations\Audit\IssueSlug;
use PublishPress\Translations\Audit\Support\JsI18nExtractor;
use PublishPress\Translations\Audit\Support\PhpI18nExtractor;
use PublishPress\Translations\Audit\Support\PoFile;

final class SourceI18nCheck implements AuditCheckInterface
{
    public function id(): string
    {
        return CheckId::SOURCE_I18N;
    }

    public function title(): string
    {
        return 'Source i18n strings vs POT coverage';
    }

    /**
     * @return AuditFinding[]
     * @since 1.0.0
     */
    public function run(AuditContext $ctx): array
    {
        $potFiles = glob($ctx->languagesDir() . '/*.pot') ?: [];

        if ($potFiles === []) {
            return [
                new AuditFinding(
                    $this->id(),
                    'info',
                    '',
                    '',
                    'No .pot files found in languages directory',
                    null,
                    null,
                    null,
                    null,
                    null,
                    IssueSlug::NO_POT_IN_LANGUAGES
                ),
            ];
        }

        $out = $ctx->output();

        $phpFiles = PhpI18nExtractor::collectPhpFiles($ctx->pluginRoot());
        $jsFiles = JsI18nExtractor::collectJsFiles($ctx->pluginRoot());

        $excludePaths = $ctx->options()->sourceExcludePaths();
        $phpFiles = self::filterByExcludedPaths($phpFiles, $ctx->pluginRoot(), $excludePaths);
        $jsFiles = self::filterByExcludedPaths($jsFiles, $ctx->pluginRoot(), $excludePaths);

        $out->step(sprintf('Collecting source files — %d PHP, %d JS/JSX', count($phpFiles), count($jsFiles)));

        $out->step(sprintf('Extracting PHP i18n strings (%d files)…', count($phpFiles)));
        $allCalls = PhpI18nExtractor::extractFromFiles($phpFiles);
        $out->bullet(count($allCalls) . ' PHP calls found');

        $out->step(sprintf('Extracting JS/JSX i18n strings (%d files)…', count($jsFiles)));
        $jsParseErrors = [];
        $jsCalls = [];
        $jsTotal = count($jsFiles);
        foreach ($jsFiles as $idx => $jsFile) {
            if ($jsTotal >= 10 && ($idx % 25 === 0 || $idx === $jsTotal - 1)) {
                $out->bullet(sprintf('%d / %d files', $idx + 1, $jsTotal));
            }
            foreach (JsI18nExtractor::extractFromFile($jsFile, $jsParseErrors) as $call) {
                $jsCalls[] = $call;
            }
        }
        $out->bullet(count($jsCalls) . ' JS calls found' . (count($jsParseErrors) > 0 ? ', ' . count($jsParseErrors) . ' parse error(s)' : ''));

        $allCalls = array_merge($allCalls, $jsCalls);

        $findings = [];

        foreach ($jsParseErrors as $parseErr) {
            $relFile    = ltrim(str_replace($ctx->pluginRoot(), '', $parseErr['file']), '/\\');
            $findings[] = new AuditFinding(
                $this->id(),
                'warning',
                $relFile,
                '',
                'JS parse error (strings in this file are not checked): ' . $parseErr['error'],
                null,
                null,
                null,
                null,
                'JS file could not be parsed — strings unchecked',
                IssueSlug::JS_PARSE_ERROR
            );
        }

        foreach ($potFiles as $potPath) {
            $potName = basename($potPath);
            $textDomain = basename($potPath, '.pot');

            $out->step('Checking against ' . $potName . '…');

            try {
                $potFile = PoFile::fromFile($potPath, false);
            } catch (\Throwable $e) {
                $findings[] = new AuditFinding(
                    $this->id(),
                    'warning',
                    ltrim(str_replace($ctx->pluginRoot(), '', $potPath), '/\\'),
                    '',
                    'Failed to parse POT: ' . $e->getMessage(),
                    null,
                    null,
                    null,
                    null,
                    null,
                    IssueSlug::POT_PARSE_ERROR
                );
                continue;
            }

            $potKeys = $potFile->msgidKeySet();
            $domainCalls = array_filter($allCalls, static function (array $call) use ($textDomain): bool {
                return $call['domain'] === $textDomain;
            });
            $domainCalls = array_values($domainCalls);

            $totalCalls = count($domainCalls);
            $skippedDynamic = count($allCalls) - $totalCalls;
            $missingCount = 0;

            /** @var array<string, array{file: string, line: int, count: int}> $seen */
            $seen = [];

            foreach ($domainCalls as $call) {
                // gettext v4 Translation::getId() always stores keys as context . "\x04" . original,
                // using empty-string context for entries with no msgctxt.
                $potKey = ($call['context'] ?? '') . "\x04" . $call['text'];

                if (isset($potKeys[$potKey])) {
                    continue;
                }

                $dedupeKey = $potKey . "\x00" . $potName;

                if (isset($seen[$dedupeKey])) {
                    $seen[$dedupeKey]['count']++;
                    continue;
                }

                $seen[$dedupeKey] = [
                    'file' => $call['file'],
                    'line' => $call['line'],
                    'count' => 1,
                    'potKey' => $potKey,
                    'text' => $call['text'],
                    'context' => $call['context'],
                ];

                $missingCount++;
            }

            foreach ($seen as $dedupeKey => $info) {
                $truncated = strlen($info['text']) > 100
                    ? substr($info['text'], 0, 97) . '...'
                    : $info['text'];

                $suffix  = $info['count'] > 1 ? ' (' . $info['count'] . ' occurrences)' : '';
                $message = 'String not found in ' . $potName . ': "' . $truncated . '"' . $suffix;

                $detailLine = '"' . $info['text'] . '"';
                if ($info['context'] !== null) {
                    $detailLine .= ' [context: ' . $info['context'] . ']';
                }

                $relFile = ltrim(str_replace($ctx->pluginRoot(), '', $info['file']), '/\\');

                $findings[] = new AuditFinding(
                    $this->id(),
                    'warning',
                    $relFile,
                    '',
                    $message,
                    null,
                    null,
                    null,
                    [$detailLine],
                    'Source string missing from ' . $potName,
                    IssueSlug::SOURCE_STRING_MISSING_FROM_POT
                );
            }

            $findings[] = new AuditFinding(
                $this->id(),
                $missingCount > 0 ? 'warning' : 'info',
                $potName,
                '',
                sprintf(
                    "Scanned %d i18n call(s) with domain '%s'; %d missing from %s, %d dynamic/skipped",
                    $totalCalls,
                    $textDomain,
                    $missingCount,
                    $potName,
                    $skippedDynamic
                ),
                null,
                null,
                null,
                null,
                null,
                IssueSlug::SOURCE_I18N_SUMMARY
            );
        }

        return $findings;
    }

    /**
     * Removes files whose path (relative to $root, normalized to forward slashes) contains
     * any of the $excludePaths substrings.
     *
     * @param string[] $files
     * @param string[] $excludePaths
     * @return string[]
     */
    private static function filterByExcludedPaths(array $files, string $root, array $excludePaths): array
    {
        if ($excludePaths === []) {
            return $files;
        }

        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';

        return array_values(array_filter($files, static function (string $file) use ($root, $excludePaths): bool {
            $rel = str_replace('\\', '/', $file);
            if (strpos($rel, $root) === 0) {
                $rel = substr($rel, strlen($root));
            }

            foreach ($excludePaths as $pattern) {
                $pattern = trim(str_replace('\\', '/', $pattern), '/');
                if ($pattern === '') {
                    continue;
                }
                if (
                    strpos($rel, $pattern . '/') === 0
                    || strpos($rel, '/' . $pattern . '/') !== false
                    || strpos($rel, '/' . $pattern) === strlen($rel) - strlen('/' . $pattern)
                ) {
                    return false;
                }
            }

            return true;
        }));
    }
}
