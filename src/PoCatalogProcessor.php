<?php

/**
 * AST-based PO normalization and cleanup routines.
 *
 * @package PublishPress\Translations
 */

namespace PublishPress\Translations;

use Gettext\Translation;

class PoCatalogProcessor
{
    /**
     * Normalize uploaded PO files for Weblate compatibility.
     *
     * @param string $languageCode WordPress language code
     * @param string $poFilePath
     * @return bool True when file was modified.
     */
    public function normalizeForWeblate($languageCode, $poFilePath)
    {
        $catalog = PoCatalog::fromFile($poFilePath);
        if (!$catalog) {
            return false;
        }

        $translations = $catalog->getTranslations();

        // Header fuzzy must not be present in upload payloads.
        $translations->getFlags()->delete('fuzzy');

        $expectedPluralForms = $this->getWeblatePluralForms($languageCode);
        if ($expectedPluralForms) {
            $normalizedLanguage = str_replace('-', '_', $languageCode);
            $translations->getHeaders()->set('Language', $normalizedLanguage);

            if ($languageCode === 'ja') {
                $translations->getHeaders()->delete('Plural-Forms');
            } else {
                $translations->getHeaders()->set('Plural-Forms', $expectedPluralForms);
            }

            $nplurals = $this->extractNplurals($expectedPluralForms);
            foreach ($catalog->getEntries() as $entry) {
                if (!$entry instanceof Translation || $entry->getPlural() === null) {
                    continue;
                }

                $allValues = array_merge(
                    [$entry->getTranslation() ?: ''],
                    $entry->getPluralTranslations()
                );

                $normalizedValues = [];
                for ($i = 0; $i < $nplurals; $i++) {
                    $normalizedValues[] = $allValues[$i] ?? '';
                }

                $entry->translate($normalizedValues[0] ?? '');
                $entry->translatePlural(...array_slice($normalizedValues, 1));
            }
        }

        // Serializing the AST also removes malformed duplicate headers/references.
        return $catalog->saveIfChanged();
    }

    /**
     * Apply post-translation PO cleanup/transforms.
     *
     * @param string      $poFilePath
     * @param string|null $pluginName
     * @return bool True when file was modified.
     */
    public function normalizeAfterTranslation($poFilePath, $pluginName = null)
    {
        $catalog = PoCatalog::fromFile($poFilePath);
        if (!$catalog) {
            return false;
        }

        $changed = false;
        foreach ($catalog->getEntries() as $entry) {
            if (!$entry instanceof Translation) {
                continue;
            }

            $changed = $this->repairPipeDelimitedPluralEntry($entry) || $changed;
            $changed = $this->markIdenticalAsFuzzyEntry($entry) || $changed;
        }

        if ($pluginName) {
            $entry = $catalog->findEntry(null, $pluginName);
            if ($entry instanceof Translation && $entry->getPlural() === null && $entry->getTranslation() !== $pluginName) {
                $entry->translate($pluginName);
                $changed = true;
            }
        }

        if (!$changed) {
            return false;
        }

        return $catalog->saveIfChanged();
    }

    /**
     * Canonicalize a PO file by loading and re-serializing the AST.
     *
     * @param string $poFilePath
     * @return bool True when file was modified.
     */
    public function canonicalize($poFilePath)
    {
        $catalog = PoCatalog::fromFile($poFilePath);
        if (!$catalog) {
            return false;
        }

        return $catalog->saveIfChanged();
    }

    /**
     * Normalize a downloaded PO file and keep plugin name untranslated.
     *
     * @param string      $poFilePath
     * @param string|null $pluginName
     * @return bool
     */
    public function normalizeDownloadedFile($poFilePath, $pluginName = null)
    {
        $catalog = PoCatalog::fromFile($poFilePath);
        if (!$catalog) {
            return false;
        }

        if ($pluginName) {
            $this->enforcePluginNameTranslation($catalog, $pluginName);
        }

        return $catalog->saveIfChanged();
    }

    /**
     * Repair only plural entries where msgstr[0] has pipe-delimited forms.
     *
     * @param string $poFilePath
     * @return bool
     */
    public function repairPipeDelimitedPlurals($poFilePath)
    {
        $catalog = PoCatalog::fromFile($poFilePath);
        if (!$catalog) {
            return false;
        }

        $changed = false;
        foreach ($catalog->getEntries() as $entry) {
            if (!$entry instanceof Translation) {
                continue;
            }

            $changed = $this->repairPipeDelimitedPluralEntry($entry) || $changed;
        }

        if (!$changed) {
            return false;
        }

        return $catalog->saveIfChanged();
    }

    /**
     * Enforce plugin name entry to remain untranslated.
     *
     * @param string      $poFilePath
     * @param string|null $pluginName
     * @return bool
     */
    public function enforcePluginNameUntranslated($poFilePath, $pluginName = null)
    {
        if (!$pluginName) {
            return false;
        }

        $catalog = PoCatalog::fromFile($poFilePath);
        if (!$catalog) {
            return false;
        }

        $changed = $this->enforcePluginNameTranslation($catalog, $pluginName);
        if (!$changed) {
            return false;
        }

        return $catalog->saveIfChanged();
    }

    /**
     * Mark identical singular translations as fuzzy.
     *
     * @param string $poFilePath
     * @return bool
     */
    public function markIdenticalAsFuzzy($poFilePath)
    {
        $catalog = PoCatalog::fromFile($poFilePath);
        if (!$catalog) {
            return false;
        }

        $changed = false;
        foreach ($catalog->getEntries() as $entry) {
            if (!$entry instanceof Translation) {
                continue;
            }

            $changed = $this->markIdenticalAsFuzzyEntry($entry) || $changed;
        }

        if (!$changed) {
            return false;
        }

        return $catalog->saveIfChanged();
    }

    /**
     * @param Translation $entry
     * @return bool
     */
    private function repairPipeDelimitedPluralEntry(Translation $entry)
    {
        if ($entry->getPlural() === null) {
            return false;
        }

        $first = $entry->getTranslation() ?: '';
        if (strpos($first, '|') === false) {
            return false;
        }

        $rest = $entry->getPluralTranslations();
        foreach ($rest as $value) {
            if ($value !== '') {
                return false;
            }
        }

        $forms = array_map('trim', explode('|', $first));
        if (empty($forms)) {
            return false;
        }

        $nplurals = max(count($forms), 1 + count($rest));
        $normalizedValues = [];
        for ($i = 0; $i < $nplurals; $i++) {
            $normalizedValues[] = $forms[$i] ?? '';
        }

        $entry->translate($normalizedValues[0] ?? '');
        $entry->translatePlural(...array_slice($normalizedValues, 1));

        return true;
    }

    /**
     * @param Translation $entry
     * @return bool
     */
    private function markIdenticalAsFuzzyEntry(Translation $entry)
    {
        if ($entry->getOriginal() === '') {
            return false;
        }

        if ($entry->getPlural() !== null) {
            return false;
        }

        $translation = $entry->getTranslation() ?: '';
        $original = $entry->getOriginal();

        if ($translation === '' || $translation !== $original) {
            return false;
        }

        if ($entry->getFlags()->has('fuzzy')) {
            return false;
        }

        $entry->getFlags()->add('fuzzy');

        return true;
    }

    /**
     * @param PoCatalog $catalog
     * @param string    $pluginName
     * @return bool
     */
    private function enforcePluginNameTranslation(PoCatalog $catalog, $pluginName)
    {
        $entry = $catalog->findEntry(null, $pluginName);
        if (!$entry instanceof Translation || $entry->getPlural() !== null || $entry->getTranslation() === $pluginName) {
            return false;
        }

        $entry->translate($pluginName);

        return true;
    }

    /**
     * @param string $pluralForms
     * @return int
     */
    private function extractNplurals($pluralForms)
    {
        $nplurals = 1;
        if (preg_match('/nplurals\s*=\s*(\d+)/', $pluralForms, $matches)) {
            $nplurals = max(1, (int) $matches[1]);
        }

        return $nplurals;
    }

    /**
     * Return Weblate's expected Plural-Forms rule for specific languages.
     *
     * @param string $languageCode WordPress language code
     * @return string|null
     */
    private function getWeblatePluralForms($languageCode)
    {
        $map = [
            'he' => 'nplurals=4; plural=(n == 1 ? 0 : (n == 2 ? 1 : ((n > 10 && n % 10 == 0) ? 2 : 3)));',
            'he_IL' => 'nplurals=4; plural=(n == 1 ? 0 : (n == 2 ? 1 : ((n > 10 && n % 10 == 0) ? 2 : 3)));',
            'ja' => 'nplurals=1; plural=0;',
            'yor' => 'nplurals=1; plural=0;',
            'fil' => 'nplurals=2; plural=n != 1 && n != 2 && n != 3 && (n % 10 == 4 || n % 10 == 6 || n % 10 == 9);',
            'fa' => 'nplurals=2; plural=(n > 1);',
            'fa_IR' => 'nplurals=2; plural=(n > 1);',
            'fr_FR' => 'nplurals=2; plural=(n > 1);',
            'pt' => 'nplurals=2; plural=n > 1;',
            'pt_PT' => 'nplurals=2; plural=n > 1;',
            'tr' => 'nplurals=2; plural=(n != 1);',
            'tr_TR' => 'nplurals=2; plural=(n != 1);',
            'th' => 'nplurals=1; plural=0;',
            'vi' => 'nplurals=1; plural=0;',
            'zh_CN' => 'nplurals=1; plural=0;',
            'zh_TW' => 'nplurals=1; plural=0;',
            'ko_KR' => 'nplurals=1; plural=0;',
            'ar' => 'nplurals=6; plural=(n == 0 ? 0 : n == 1 ? 1 : n == 2 ? 2 : n % 100 >= 3 && n % 100 <= 10 ? 3 : n % 100 >= 11 ? 4 : 5);',
            'pl_PL' => 'nplurals=3; plural=(n == 1 ? 0 : n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20) ? 1 : 2);',
            'hr' => 'nplurals=3; plural=(n % 10 == 1 && n % 100 != 11 ? 0 : n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20) ? 1 : 2);',
            'cs_CZ' => 'nplurals=3; plural=(n == 1) ? 0 : (n >= 2 && n <= 4) ? 1 : 2;',
            'sk_SK' => 'nplurals=3; plural=(n == 1) ? 0 : (n >= 2 && n <= 4) ? 1 : 2;',
            'uk' => 'nplurals=3; plural=(n % 10 == 1 && n % 100 != 11 ? 0 : n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20) ? 1 : 2);',
            'ru_RU' => 'nplurals=3; plural=(n % 10 == 1 && n % 100 != 11 ? 0 : n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20) ? 1 : 2);',
            'lt_LT' => 'nplurals=3; plural=(n % 10 == 1 && n % 100 != 11 ? 0 : n % 10 >= 2 && (n % 100 < 10 || n % 100 >= 20) ? 1 : 2);',
        ];

        return isset($map[$languageCode]) ? $map[$languageCode] : null;
    }
}
