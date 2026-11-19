<?php

/**
 * Compare and align sprintf placeholders between a source string and a translation.
 *
 * Potomatic sometimes adds, drops, or renumbers %s / %1$s tokens, and it sometimes
 * writes class=\"count\" with an extra backslash. PHP 8 fatals when a translation
 * has more placeholders than the source, or when one string mixes %s and %1$s.
 *
 * @package PublishPress\Translations\Support
 */

namespace PublishPress\Translations\Support;

final class PrintfPlaceholder
{
    /**
     * @param string $source      Decoded msgid (or msgid_plural for plural forms).
     * @param string $translation Decoded msgstr. Empty means untranslated.
     * @return array{action:string,value:string,reason:string}
     */
    public static function align(string $source, string $translation): array
    {
        if ($translation === '') {
            return [
                'action' => 'skip',
                'value'  => $translation,
                'reason' => '',
            ];
        }

        $normalized = self::normalize($translation);
        $quoted     = self::fixExtraEscapes($source, $normalized);
        $srcTokens  = self::tokens($source);
        $dstTokens  = self::tokens($quoted);

        if (self::compatible($srcTokens, $dstTokens)) {
            if ($quoted === $translation) {
                return [
                    'action' => 'ok',
                    'value'  => $translation,
                    'reason' => '',
                ];
            }

            return [
                'action' => 'rewritten',
                'value'  => $quoted,
                'reason' => self::rewriteReason($translation, $normalized, $quoted),
            ];
        }

        if (
            count($srcTokens) === count($dstTokens)
            && self::typesEqual($srcTokens, $dstTokens)
        ) {
            $rebuilt = self::replaceTokensInOrder($quoted, $dstTokens, $srcTokens);
            if (self::compatible(self::tokens($source), self::tokens($rebuilt))) {
                return [
                    'action' => 'rewritten',
                    'value'  => $rebuilt,
                    'reason' => 'renumbered placeholders to match the source',
                ];
            }
        }

        return [
            'action' => 'fallback',
            'value'  => $source,
            'reason' => self::mismatchReason($srcTokens, $dstTokens),
        ];
    }

    /**
     * @param array<int,array{raw:string,index:int|null,type:string,pos:int,invalid:bool}> $src
     * @param array<int,array{raw:string,index:int|null,type:string,pos:int,invalid:bool}> $dst
     */
    private static function mismatchReason(array $src, array $dst): string
    {
        if (self::mixes($dst)) {
            return 'translation mixes %s and %1$s';
        }

        return 'placeholder count ' . count($dst) . ' != ' . count($src);
    }

    private static function rewriteReason(string $original, string $normalized, string $quoted): string
    {
        if ($quoted !== $normalized) {
            return 'removed extra backslash before a quote';
        }

        if ($normalized !== $original) {
            return 'normalized placeholder spelling';
        }

        return 'aligned placeholders';
    }

    /**
     * Collapse spaces inside %1$s and turn a fullwidth percent into %.
     */
    public static function normalize(string $value): string
    {
        $value = str_replace("\xEF\xBC\x85", '%', $value);

        $replaced = preg_replace_callback(
            '/%\s*(\d+)\s*\$\s*([bcdeEfFgGosuxX])/',
            static function (array $match): string {
                return '%' . $match[1] . '$' . $match[2];
            },
            $value
        );

        return is_string($replaced) ? $replaced : $value;
    }

    /**
     * Potomatic writes class=\\"count\\" in the decoded string. The source has class="count".
     */
    public static function fixExtraEscapes(string $source, string $translation): string
    {
        if (strpos($translation, '\\"') === false) {
            return $translation;
        }

        if (strpos($source, '\\') !== false) {
            return $translation;
        }

        return str_replace('\\"', '"', $translation);
    }

    /**
     * @return array<int,array{raw:string,index:int|null,type:string,pos:int,invalid:bool}>
     */
    public static function tokens(string $value): array
    {
        $out = [];
        $len = strlen($value);
        $i   = 0;

        while ($i < $len) {
            if ($value[$i] !== '%') {
                $i++;
                continue;
            }

            if ($i + 1 < $len && $value[$i + 1] === '%') {
                $i += 2;
                continue;
            }

            $slice = substr($value, $i);
            if (preg_match('/^%(?:(\d+)\$)?[+-]?(?:\d+)?(?:\.\d+)?([bcdeEfFgGosuxX])/', $slice, $match) === 1) {
                $index = ($match[1] !== '') ? (int) $match[1] : null;

                $out[] = [
                    'raw'     => $match[0],
                    'index'   => $index,
                    'type'    => $match[2],
                    'pos'     => $i,
                    'invalid' => false,
                ];
                $i += strlen($match[0]);
                continue;
            }

            $out[] = [
                'raw'     => '%',
                'index'   => null,
                'type'    => '',
                'pos'     => $i,
                'invalid' => true,
            ];
            $i++;
        }

        return $out;
    }

    /**
     * @param array<int,array{raw:string,index:int|null,type:string,pos:int,invalid:bool}> $src
     * @param array<int,array{raw:string,index:int|null,type:string,pos:int,invalid:bool}> $dst
     */
    public static function compatible(array $src, array $dst): bool
    {
        if (count($src) !== count($dst)) {
            return false;
        }

        if ($src === []) {
            return true;
        }

        if (self::mixes($src) || self::mixes($dst) || self::hasInvalid($src) || self::hasInvalid($dst)) {
            return self::keys($src) === self::keys($dst);
        }

        $srcNumbered = $src[0]['index'] !== null;
        $dstNumbered = $dst[0]['index'] !== null;

        if ($srcNumbered === $dstNumbered) {
            if ($srcNumbered) {
                $left  = self::keys($src);
                $right = self::keys($dst);
                sort($left);
                sort($right);

                return $left === $right;
            }

            return self::keys($src) === self::keys($dst);
        }

        if (!self::typesEqual($src, $dst)) {
            return false;
        }

        $numbered = $srcNumbered ? $src : $dst;
        $indexes  = [];
        foreach ($numbered as $token) {
            $indexes[] = (int) $token['index'];
        }
        $sorted = $indexes;
        sort($sorted);

        $expect = range(1, count($numbered));

        return $sorted === $expect;
    }

    /**
     * @param array<int,array{raw:string,index:int|null,type:string,pos:int,invalid:bool}> $tokens
     */
    private static function mixes(array $tokens): bool
    {
        $numbered   = false;
        $unnumbered = false;

        foreach ($tokens as $token) {
            if ($token['invalid']) {
                continue;
            }
            if ($token['index'] === null) {
                $unnumbered = true;
            } else {
                $numbered = true;
            }
        }

        return $numbered && $unnumbered;
    }

    /**
     * @param array<int,array{raw:string,index:int|null,type:string,pos:int,invalid:bool}> $tokens
     */
    private static function hasInvalid(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token['invalid']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,array{raw:string,index:int|null,type:string,pos:int,invalid:bool}> $a
     * @param array<int,array{raw:string,index:int|null,type:string,pos:int,invalid:bool}> $b
     */
    private static function typesEqual(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }

        $count = count($a);
        for ($i = 0; $i < $count; $i++) {
            if ($a[$i]['invalid'] || $b[$i]['invalid']) {
                return false;
            }
            if ($a[$i]['type'] !== $b[$i]['type']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int,array{raw:string,index:int|null,type:string,pos:int,invalid:bool}> $tokens
     * @return string[]
     */
    private static function keys(array $tokens): array
    {
        $keys = [];
        foreach ($tokens as $token) {
            if ($token['invalid']) {
                $keys[] = '?';
                continue;
            }
            if ($token['index'] === null) {
                $keys[] = 'u:' . $token['type'];
                continue;
            }
            $keys[] = 'n:' . $token['index'] . ':' . $token['type'];
        }

        return $keys;
    }

    /**
     * @param array<int,array{raw:string,index:int|null,type:string,pos:int,invalid:bool}> $from
     * @param array<int,array{raw:string,index:int|null,type:string,pos:int,invalid:bool}> $to
     */
    private static function replaceTokensInOrder(string $value, array $from, array $to): string
    {
        $out    = '';
        $cursor = 0;
        $count  = count($from);

        for ($i = 0; $i < $count; $i++) {
            $pos = $from[$i]['pos'];
            $raw = $from[$i]['raw'];
            $out .= substr($value, $cursor, $pos - $cursor);
            $out .= $to[$i]['raw'];
            $cursor = $pos + strlen($raw);
        }

        $out .= substr($value, $cursor);

        return $out;
    }
}
