<?php

/**
 * TRANSLATION_OVERRIDES / TRANSLATION_OVERRIDES_{locale} parsing (shared by Translator and audit).
 *
 * @package PublishPress\Translations\Support
 */

namespace PublishPress\Translations\Support;

final class TranslationOverrides
{
    /**
     * Parse a TRANSLATION_OVERRIDES env var value into a flat override map.
     *
     * @param mixed $envValue Raw env var value
     *
     * @return array<string, string> source => target (bare word entries map word => word)
     */
    public static function parseEnvValue($envValue)
    {
        $overrides = [];

        if (!is_string($envValue) || trim($envValue) === '') {
            return $overrides;
        }

        $entries = array_filter(array_map('trim', explode(',', $envValue)));

        foreach ($entries as $entry) {
            $equalsIndex = strpos($entry, '=');
            if ($equalsIndex !== false && $equalsIndex > 0) {
                $source = trim(substr($entry, 0, $equalsIndex));
                $target = trim(substr($entry, $equalsIndex + 1));
                if ($source !== '' && $target !== '') {
                    $overrides[$source] = $target;
                }
            } else {
                $word = trim($entry);
                if ($word !== '') {
                    $overrides[$word] = $word;
                }
            }
        }

        return $overrides;
    }

    /**
     * Merged overrides for a locale: TRANSLATION_OVERRIDES then TRANSLATION_OVERRIDES_{language}.
     * Per-language keys overwrite global entries (same as Translator::getOverridesForLanguage).
     *
     * @return array<string, string>
     */
    public static function mapForLanguage(string $language)
    {
        $overrides = [];

        $envGlobal = getenv('TRANSLATION_OVERRIDES');
        if ($envGlobal !== false && trim($envGlobal) !== '') {
            foreach (self::parseEnvValue($envGlobal) as $source => $target) {
                $overrides[$source] = $target;
            }
        }

        $envKey = 'TRANSLATION_OVERRIDES_' . $language;
        $envVal = getenv($envKey);
        if ($envVal !== false && trim($envVal) !== '') {
            foreach (self::parseEnvValue($envVal) as $source => $target) {
                $overrides[$source] = $target;
            }
        }

        return $overrides;
    }
}
