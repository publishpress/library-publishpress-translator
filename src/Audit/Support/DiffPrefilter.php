<?php

/**
 * Cheap normalization before AI worthiness calls.
 *
 * @package PublishPress\Translations\Audit\Support
 */

namespace PublishPress\Translations\Audit\Support;

final class DiffPrefilter
{
    /**
     * True if old/new differ only cosmetically after normalization.
     */
    public static function isCosmeticOnly(string $old, string $new): bool
    {
        return self::normalize($old) === self::normalize($new);
    }

    public static function normalize(string $s): string
    {
        if (function_exists('normalizer_normalize') && class_exists('Normalizer')) {
            $s = normalizer_normalize($s, \Normalizer::FORM_C) ?: $s;
        }

        $s = str_replace(
            ["\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\x98", "\xE2\x80\x99"],
            ['"', '"', "'", "'"],
            $s
        );

        $s = preg_replace('/\s+/u', ' ', $s);
        if ($s === null) {
            return '';
        }

        return trim($s);
    }
}
