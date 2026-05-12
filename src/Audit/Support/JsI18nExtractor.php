<?php

/**
 * AST-based extractor for WordPress i18n function calls in JS/JSX/TS/TSX source files.
 *
 * @package PublishPress\Translations\Audit\Support
 * @author PublishPress
 * @copyright Copyright (c) 2026, PublishPress
 * @license GPL v2 or later
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PublishPress\Translations\Audit\Support;

final class JsI18nExtractor
{
    /**
     * Default directory names to skip during recursive JS file collection.
     */
    private const DEFAULT_EXCLUDE_DIRS = ['vendor', 'node_modules', '.git', 'tests', 'test', 'dist', 'build', 'dev-workspace-cache'];

    /**
     * File extensions collected by collectJsFiles.
     */
    private const JS_EXTENSIONS = ['js', 'jsx', 'ts', 'tsx'];

    /**
     * Maximum file size in bytes to attempt parsing; larger files are skipped as compiled output.
     */
    private const MAX_FILE_SIZE = 524288; // 512 KB

    /**
     * Maps lowercase function names to argument position descriptors.
     *
     * Each entry: text index, plural index (int|null), context index (int|null), domain index.
     *
     * @var array<string, array{text: int, plural: int|null, context: int|null, domain: int}>
     */
    private const FUNCTION_SIGNATURES = [
        '__'       => ['text' => 0, 'plural' => null, 'context' => null, 'domain' => 1],
        '_e'       => ['text' => 0, 'plural' => null, 'context' => null, 'domain' => 1],
        '_x'       => ['text' => 0, 'plural' => null, 'context' => 1,    'domain' => 2],
        '_ex'      => ['text' => 0, 'plural' => null, 'context' => 1,    'domain' => 2],
        '_n'       => ['text' => 0, 'plural' => 1,    'context' => null, 'domain' => 3],
        '_nx'      => ['text' => 0, 'plural' => 1,    'context' => 3,    'domain' => 4],
        '_n_noop'  => ['text' => 0, 'plural' => 1,    'context' => null, 'domain' => 2],
        '_nx_noop' => ['text' => 0, 'plural' => 1,    'context' => 2,    'domain' => 3],
    ];

    /**
     * Extracts all WordPress i18n function calls from a single JS/JSX/TS/TSX file.
     *
     * Only calls where both the text and domain are static string literals are returned;
     * dynamic arguments (template literals, variables, etc.) produce null entries which
     * are silently skipped. Files that exceed MAX_FILE_SIZE are skipped without error
     * (assumed compiled output). Genuine parse failures are appended to $parseErrors as
     * ['file' => string, 'error' => string] entries so callers can surface them.
     *
     * @param string                                         $file        Absolute or relative path to the JS source file
     * @param array<int, array{file: string, error: string}>|null $parseErrors Collector for parse failures; pass by reference
     * @return array<int, array{file: string, line: int, function: string, text: string, plural: string|null, context: string|null, domain: string|null}>
     * @since 1.0.0
     */
    public static function extractFromFile(string $file, ?array &$parseErrors = null): array
    {
        $fileSize = @filesize($file);
        if ($fileSize === false || $fileSize > self::MAX_FILE_SIZE) {
            return [];
        }

        $source = @file_get_contents($file);
        if ($source === false) {
            return [];
        }

        $parseError = null;
        $ast        = self::parseSource($source, $parseError);
        if ($ast === null) {
            if ($parseErrors !== null && $parseError !== null) {
                $parseErrors[] = ['file' => $file, 'error' => $parseError];
            }

            return [];
        }

        $results = [];

        $ast->traverse(function ($node) use (&$results, $file): void {
            if ($node->getType() !== 'CallExpression') {
                return;
            }

            $callee = $node->getCallee();
            if ($callee->getType() !== 'Identifier') {
                return;
            }

            $name = strtolower($callee->getName());
            if (!isset(self::FUNCTION_SIGNATURES[$name])) {
                return;
            }

            $sig    = self::FUNCTION_SIGNATURES[$name];
            $args   = $node->getArguments();
            $line   = $node->getLocation()->getStart()->getLine();

            $result = self::buildResult($args, $sig, $file, $line, $name);
            if ($result !== null) {
                $results[] = $result;
            }
        });

        return $results;
    }

    /**
     * Extracts i18n calls from multiple JS files and merges results.
     *
     * Parse failures for individual files are appended to $parseErrors as
     * ['file' => string, 'error' => string] entries.
     *
     * @param string[]                                           $files
     * @param array<int, array{file: string, error: string}>|null $parseErrors Collector for parse failures; pass by reference
     * @return array<int, array{file: string, line: int, function: string, text: string, plural: string|null, context: string|null, domain: string|null}>
     * @since 1.0.0
     */
    public static function extractFromFiles(array $files, ?array &$parseErrors = null): array
    {
        $results = [];
        foreach ($files as $file) {
            foreach (self::extractFromFile($file, $parseErrors) as $call) {
                $results[] = $call;
            }
        }

        return $results;
    }

    /**
     * Recursively collects JS/JSX/TS/TSX files under $dir, skipping the specified directory names.
     *
     * @param string        $dir
     * @param string[]|null $excludeDirs Directory base-names to skip; null uses the default list
     * @return string[]
     * @since 1.0.0
     */
    public static function collectJsFiles(string $dir, ?array $excludeDirs = null): array
    {
        if ($excludeDirs === null) {
            $excludeDirs = self::DEFAULT_EXCLUDE_DIRS;
        }

        $files = [];

        if (!is_dir($dir)) {
            return $files;
        }

        $dirIter = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);

        $filtered = new \RecursiveCallbackFilterIterator(
            $dirIter,
            static function (\SplFileInfo $item) use ($excludeDirs): bool {
                if ($item->isDir()) {
                    return !in_array($item->getBasename(), $excludeDirs, true);
                }

                return true;
            }
        );

        $iter = new \RecursiveIteratorIterator($filtered);

        foreach ($iter as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && in_array($file->getExtension(), self::JS_EXTENSIONS, true)) {
                $files[] = $file->getRealPath();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Attempts to parse JS/JSX source, trying module mode first, falling back to script mode.
     *
     * On failure, $error is set to a human-readable message combining both attempts.
     *
     * @param string      $source
     * @param string|null $error Set to the parse error message when null is returned
     * @return \Peast\Syntax\Node\Program|null
     * @since 1.0.0
     */
    private static function parseSource(string $source, ?string &$error = null): ?\Peast\Syntax\Node\Program
    {
        $moduleError = null;

        try {
            return \Peast\Peast::ES2025($source, ['jsx' => true, 'sourceType' => 'module'])->parse();
        } catch (\Throwable $e) {
            $moduleError = $e->getMessage();
        }

        try {
            return \Peast\Peast::ES2025($source, ['jsx' => true, 'sourceType' => 'script'])->parse();
        } catch (\Throwable $e) {
            $error = 'Parse failed (module: ' . $moduleError . '; script: ' . $e->getMessage() . ')';

            return null;
        }
    }

    /**
     * Returns the string value of an argument node if it is a string literal, null otherwise.
     *
     * Template literals, variables, and any non-literal expressions return null.
     *
     * @param object|null $arg
     * @return string|null
     * @since 1.0.0
     */
    private static function argAsLiteralString(?object $arg): ?string
    {
        if ($arg === null) {
            return null;
        }

        if ($arg->getType() !== 'Literal') {
            return null;
        }

        $value = $arg->getValue();

        return is_string($value) ? $value : null;
    }

    /**
     * Builds a result entry from AST argument nodes based on the function signature.
     *
     * Returns null when text or domain is not a static string literal.
     *
     * @param array<int, object>                                                    $args
     * @param array{text: int, plural: int|null, context: int|null, domain: int}   $sig
     * @param string                                                                $file
     * @param int                                                                   $line
     * @param string                                                                $funcName
     * @return array{file: string, line: int, function: string, text: string, plural: string|null, context: string|null, domain: string|null}|null
     * @since 1.0.0
     */
    private static function buildResult(
        array $args,
        array $sig,
        string $file,
        int $line,
        string $funcName
    ): ?array {
        $getArg = static function (int $idx) use ($args): ?string {
            return self::argAsLiteralString($args[$idx] ?? null);
        };

        $text   = $getArg($sig['text']);
        $domain = $getArg($sig['domain']);

        if ($text === null || $domain === null) {
            return null;
        }

        return [
            'file'     => $file,
            'line'     => $line,
            'function' => $funcName,
            'text'     => $text,
            'plural'   => $sig['plural'] !== null ? $getArg($sig['plural']) : null,
            'context'  => $sig['context'] !== null ? $getArg($sig['context']) : null,
            'domain'   => $domain,
        ];
    }
}
