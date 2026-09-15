<?php

/**
 * Protected source strings that may intentionally remain untranslated.
 *
 * @package PublishPress\Translations\Support
 */

namespace PublishPress\Translations\Support;

final class IdenticalTranslationPolicy
{
    /** @var string[] */
    private $protectedSources = [];

    public function __construct(string $language, string $pluginName = '')
    {
        $this->addDictionaryDefaults();

        if ($pluginName !== '') {
            $this->protectedSources[] = $pluginName;
        }

        foreach (TranslationOverrides::mapForLanguage($language) as $source => $target) {
            $this->addIdentityMapping($source, $target);
        }
    }

    public function isProtected(string $source): bool
    {
        foreach ($this->protectedSources as $protectedSource) {
            if (strcasecmp($source, $protectedSource) === 0) {
                return true;
            }
        }

        return false;
    }

    public function isLinkOnly(string $source): bool
    {
        $value = trim($source);

        if (preg_match('#^(?:https?://|mailto:|tel:)[^\s]+$#i', $value)) {
            return true;
        }

        return (bool) preg_match(
            '#^<a\b[^>]*href=["\'](?:https?://|mailto:|tel:)[^"\']+["\'][^>]*>[^<]*</a>$#i',
            $value
        );
    }

    private function addDictionaryDefaults(): void
    {
        $path = dirname(__DIR__, 2) . '/config/dictionaries.json';
        $content = @file_get_contents($path);
        if ($content === false || $content === '') {
            return;
        }

        $dictionary = json_decode($content, true);
        if (!is_array($dictionary)) {
            return;
        }

        foreach ($dictionary as $entries) {
            if (!is_array($entries)) {
                continue;
            }

            foreach ($entries as $source => $target) {
                if (is_string($source) && is_string($target)) {
                    $this->addIdentityMapping($source, $target);
                }
            }
        }
    }

    private function addIdentityMapping(string $source, string $target): void
    {
        if ($source !== '' && strcasecmp($source, $target) === 0) {
            $this->protectedSources[] = $source;
        }
    }
}
