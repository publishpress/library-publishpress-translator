<?php

/**
 * Tokenizer-based extractor for WordPress i18n function calls in PHP source files.
 *
 * @package PublishPress\Translations\Audit\Support
 * @author PublishPress
 * @copyright Copyright (c) 2026, PublishPress
 * @license GPL v2 or later
 * @since 1.0.0
 */

declare(strict_types=1);

namespace PublishPress\Translations\Audit\Support;

final class PhpI18nExtractor
{
    /**
     * Default directory names to skip during recursive PHP file collection.
     */
    private const DEFAULT_EXCLUDE_DIRS = ['vendor', 'node_modules', '.git', 'tests', 'test', 'dist', 'build', 'dev-workspace-cache'];

    /**
     * Maps lowercase function names to argument position descriptors.
     *
     * Each entry: text index, plural index (int|null), context index (int|null), domain index.
     *
     * @var array<string, array{text: int, plural: int|null, context: int|null, domain: int}>
     */
    private const FUNCTION_SIGNATURES = [
        '__'          => ['text' => 0, 'plural' => null, 'context' => null, 'domain' => 1],
        '_e'          => ['text' => 0, 'plural' => null, 'context' => null, 'domain' => 1],
        'esc_html__'  => ['text' => 0, 'plural' => null, 'context' => null, 'domain' => 1],
        'esc_html_e'  => ['text' => 0, 'plural' => null, 'context' => null, 'domain' => 1],
        'esc_attr__'  => ['text' => 0, 'plural' => null, 'context' => null, 'domain' => 1],
        'esc_attr_e'  => ['text' => 0, 'plural' => null, 'context' => null, 'domain' => 1],
        '_x'          => ['text' => 0, 'plural' => null, 'context' => 1, 'domain' => 2],
        '_ex'         => ['text' => 0, 'plural' => null, 'context' => 1, 'domain' => 2],
        'esc_html_x'  => ['text' => 0, 'plural' => null, 'context' => 1, 'domain' => 2],
        'esc_html_ex' => ['text' => 0, 'plural' => null, 'context' => 1, 'domain' => 2],
        'esc_attr_x'  => ['text' => 0, 'plural' => null, 'context' => 1, 'domain' => 2],
        'esc_attr_ex' => ['text' => 0, 'plural' => null, 'context' => 1, 'domain' => 2],
        '_n'          => ['text' => 0, 'plural' => 1, 'context' => null, 'domain' => 3],
        '_n_noop'     => ['text' => 0, 'plural' => 1, 'context' => null, 'domain' => 2],
        '_nx'         => ['text' => 0, 'plural' => 1, 'context' => 3, 'domain' => 4],
        '_nx_noop'    => ['text' => 0, 'plural' => 1, 'context' => 2, 'domain' => 3],
    ];

    /**
     * Extracts all WordPress i18n function calls from a single PHP file.
     *
     * Only calls where both the text and domain are static string literals are returned;
     * dynamic arguments produce null entries which are silently skipped.
     *
     * @param string $file Absolute or relative path to the PHP source file
     * @return array<int, array{file: string, line: int, function: string, text: string, plural: string|null, context: string|null, domain: string|null}>
     * @since 1.0.0
     */
    public static function extractFromFile(string $file): array
    {
        $source = @file_get_contents($file);
        if ($source === false) {
            return [];
        }

        $tokens  = token_get_all($source, TOKEN_PARSE);
        $results = [];
        $count   = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];

            if (!is_array($tok) || $tok[0] !== T_STRING) {
                continue;
            }

            $funcName = strtolower($tok[1]);
            if (!isset(self::FUNCTION_SIGNATURES[$funcName])) {
                continue;
            }

            // Confirm next non-whitespace token is (
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }

            if ($j >= $count || $tokens[$j] !== '(') {
                continue;
            }

            $line = $tok[2];
            $j++; // move past (

            $rawArgs = self::splitArgs($tokens, $j, $count);

            $sig    = self::FUNCTION_SIGNATURES[$funcName];
            $result = self::buildResult($rawArgs, $sig, $file, $line, $funcName);

            if ($result !== null) {
                $results[] = $result;
            }

            $i = $j - 1; // loop will increment
        }

        return $results;
    }

    /**
     * Extracts i18n calls from multiple PHP files and merges results.
     *
     * @param string[] $files
     * @return array<int, array{file: string, line: int, function: string, text: string, plural: string|null, context: string|null, domain: string|null}>
     * @since 1.0.0
     */
    public static function extractFromFiles(array $files): array
    {
        $results = [];
        foreach ($files as $file) {
            foreach (self::extractFromFile($file) as $call) {
                $results[] = $call;
            }
        }

        return $results;
    }

    /**
     * Recursively collects .php files under $dir, skipping the specified directory names.
     *
     * @param string        $dir
     * @param string[]|null $excludeDirs Directory base-names to skip; null uses the default list
     * @return string[]
     * @since 1.0.0
     */
    public static function collectPhpFiles(string $dir, ?array $excludeDirs = null): array
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
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getRealPath();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Collects raw token groups for each comma-separated argument inside a function call.
     *
     * Starts at the first token after the opening ( and advances $pos past the closing ).
     *
     * @param array<int, mixed> $tokens
     * @param int               $pos    Modified in place; points to one past the ) on return
     * @param int               $count
     * @return array<int, array<int, mixed>>
     */
    private static function splitArgs(array $tokens, int &$pos, int $count): array
    {
        $args   = [[]];
        $argIdx = 0;
        $depth  = 1;

        while ($pos < $count && $depth > 0) {
            $tok = $tokens[$pos];

            if ($tok === '(') {
                $depth++;
                $args[$argIdx][] = $tok;
            } elseif ($tok === ')') {
                $depth--;
                if ($depth === 0) {
                    $pos++;
                    break;
                }
                $args[$argIdx][] = $tok;
            } elseif ($tok === ',' && $depth === 1) {
                $argIdx++;
                $args[$argIdx] = [];
            } else {
                $args[$argIdx][] = $tok;
            }

            $pos++;
        }

        return $args;
    }

    /**
     * Returns the unescaped string value of an argument if it is a single string literal, null otherwise.
     *
     * Concatenated strings, variables, heredocs, and any other non-literal expressions → null.
     *
     * @param array<int, mixed> $argTokens
     * @since 1.0.0
     */
    private static function argAsLiteralString(array $argTokens): ?string
    {
        $meaningful = array_values(array_filter(
            $argTokens,
            static function ($tok): bool {
                return !(is_array($tok) && $tok[0] === T_WHITESPACE);
            }
        ));

        if (count($meaningful) !== 1) {
            return null;
        }

        $tok = $meaningful[0];
        if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        return self::unescapeStringToken($tok[1]);
    }

    /**
     * Strips surrounding quotes and resolves escape sequences in a string token value.
     *
     * @since 1.0.0
     */
    private static function unescapeStringToken(string $token): string
    {
        if (strlen($token) < 2) {
            return '';
        }

        $quote = $token[0];
        $inner = substr($token, 1, -1);

        if ($quote === "'") {
            return str_replace(["\\\\", "\\'"], ['\\', "'"], $inner);
        }

        return self::unescapeDoubleQuoted($inner);
    }

    /**
     * Resolves PHP double-quoted string escape sequences character by character.
     *
     * Hex (\x), octal, and Unicode (\u) escapes are passed through as-is since
     * they are extremely rare in translatable strings and gettext tools do the same.
     *
     * @since 1.0.0
     */
    private static function unescapeDoubleQuoted(string $inner): string
    {
        $result = '';
        $len    = strlen($inner);
        $i      = 0;

        while ($i < $len) {
            if ($inner[$i] !== '\\') {
                $result .= $inner[$i++];
                continue;
            }

            $i++;
            if ($i >= $len) {
                $result .= '\\';
                break;
            }

            switch ($inner[$i]) {
                case 'n':
                    $result .= "\n";
                    break;
                case 't':
                    $result .= "\t";
                    break;
                case 'r':
                    $result .= "\r";
                    break;
                case 'f':
                    $result .= "\f";
                    break;
                case 'v':
                    $result .= "\v";
                    break;
                case 'e':
                    $result .= "\e";
                    break;
                case '\\':
                    $result .= '\\';
                    break;
                case '"':
                    $result .= '"';
                    break;
                case '$':
                    $result .= '$';
                    break;
                default:
                    $result .= '\\' . $inner[$i];
                    break;
            }

            $i++;
        }

        return $result;
    }

    /**
     * Builds a result entry from raw argument token groups based on the function signature.
     *
     * Returns null when text or domain is not a static literal.
     *
     * @param array<int, array<int, mixed>>                                        $rawArgs
     * @param array{text: int, plural: int|null, context: int|null, domain: int}   $sig
     * @return array{file: string, line: int, function: string, text: string, plural: string|null, context: string|null, domain: string|null}|null
     * @since 1.0.0
     */
    private static function buildResult(
        array $rawArgs,
        array $sig,
        string $file,
        int $line,
        string $funcName
    ): ?array {
        $getArg = static function (int $idx) use ($rawArgs): ?string {
            return isset($rawArgs[$idx]) ? self::argAsLiteralString($rawArgs[$idx]) : null;
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
