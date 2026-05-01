<?php

/**
 * Main Translator Class
 *
 * @package PublishPress\Translations
 */

namespace PublishPress\Translations;

use Exception;
use PublishPress\Translations\Audit\AuditOptions;
use PublishPress\Translations\Audit\Auditor;
use PublishPress\Translations\Support\TranslationOverrides;

class Translator
{
    private const CLI_BANNER_TITLE = 'PublishPress Translator';

    /**
     * Plugin root directory
     *
     * @var string
     */
    private $pluginRoot;

    /**
     * Languages directory
     *
     * @var string
     */
    private $languagesDir;

    /**
     * Languages to skip
     *
     * @var array
     */
    private $skippedLanguages = [
        'it_IT',
        'es_ES',
        'fr_FR',
    ];

    /**
     * Target languages
     *
     * @var array
     */
    private $targetLanguages = [
        'de_DE',
        'id_ID',
        'fil',
        'ru_RU',
        'yor',
        'fi',
        'ja',
        'ko_KR',
        'nl_NL',
        'pl_PL',
        'tr_TR',
        'vi',
        'fa_IR',
        'cs_CZ',
        'pt_PT',
        'pt_BR',
        'zh_CN',
        'sv_SE',
        'hu_HU',
        'da_DK',
        'ar',
        'he_IL',
        'ro_RO',
        'el',
        'th',
        'zh_TW',
        'sk_SK',
        'uk',
        'nb_NO',
        'bg_BG',
        'hr',
        'ca',
        'lt_LT',
        'et_EE',
        'sl_SI'
    ];

    /**
     * True if languages were explicitly set (via CLI --languages)
     * @var bool
    */
    private $customTargetLanguages = false;

    /**
     * Dry run mode
     *
     * @var bool
     */
    private $dryRun = false;

    /**
     * Force translate mode
     *
     * @var bool
     */
    private $forceTranslate = false;

    /**
     * Weblate integration enabled
     *
     * @var bool
     */
    private $weblateEnabled = false;

    /**
     * Weblate client
     *
     * @var WeblateClient|null
     */
    private $weblateClient = null;

    /**
     * Default Potomatic AI settings
     */
    private const DEFAULT_AI_MODEL = 'gpt-4o-mini';
    private const DEFAULT_AI_BATCH_SIZE = 20;
    private const DEFAULT_AI_JOBS = 2;
    private const DEFAULT_AI_MAX_COST = 5.0;
    private const DEFAULT_AI_VERBOSE_LEVEL = 2;

    /**
     * Potomatic settings
     *
     * @var array
     */
    private $potomaticSettings = [
        'model' => self::DEFAULT_AI_MODEL,
        'batch_size' => self::DEFAULT_AI_BATCH_SIZE,
        'jobs' => self::DEFAULT_AI_JOBS,
        'max_cost' => self::DEFAULT_AI_MAX_COST,
        'verbose_level' => self::DEFAULT_AI_VERBOSE_LEVEL,
    ];

    /**
     * Path to the temporary dictionary directory for translation overrides.
     *
     * @var string|null
     */
    private $tempDictionaryDir = null;

    /**
     * Output instance
     *
     * @var Output
     */
    private $output;

    /**
     * Audit CLI options
     *
     * @var AuditOptions
     */
    private $auditOptions;

    /**
     * Constructor
     *
     * @param string $pluginRoot Plugin root directory
     * @throws Exception
     */
    public function __construct($pluginRoot, Output $output)
    {
        $this->pluginRoot = rtrim($pluginRoot, '/\\');
        $this->languagesDir = $this->pluginRoot . '/languages';
        $this->output = $output;

        if (!is_dir($this->languagesDir)) {
            throw new Exception("Languages directory not found: {$this->languagesDir}");
        }

        // Check if Weblate is enabled
        if (getenv('WEBLATE_API_TOKEN')) {
            try {
                $this->weblateClient = new WeblateClient();
                $this->weblateEnabled = true;
            } catch (Exception $e) {
                // Weblate not configured, continue without it
                $this->weblateEnabled = false;
            }
        }

        // Allow configuring skipped languages via environment variable
        $envSkipped = getenv('SKIP_LANGUAGES');
        if ($envSkipped) {
            $customSkipped = array_map('trim', explode(',', $envSkipped));
            $this->skippedLanguages = array_merge($this->skippedLanguages, $customSkipped);
            $this->skippedLanguages = array_unique($this->skippedLanguages);
        }

        $this->auditOptions = AuditOptions::defaults();
    }

    /**
     * Set dry run mode
     *
     * @param bool $dryRun
     */
    public function setDryRun($dryRun)
    {
        $this->dryRun = (bool) $dryRun;
    }

    /**
     * Set force translate mode
     *
     * @param bool $forceTranslate
     */
    public function setForceTranslate($forceTranslate)
    {
        $this->forceTranslate = (bool) $forceTranslate;
    }

    /**
     * Set target languages
     *
     * @param array $languages
     */
    public function setTargetLanguages(array $languages)
    {
        $this->targetLanguages = array_diff($languages, $this->skippedLanguages);
        $this->customTargetLanguages = true;
    }

    public function setAuditMode($mode)
    {
        $this->auditOptions = $this->auditOptions->withMode((string) $mode);
    }

    /**
     * @param float $usd
     */
    public function setAuditMaxCost($usd)
    {
        $this->auditOptions = $this->auditOptions->withMaxCost((float) $usd);
    }

    /**
     * @param array $checks CheckId values
     */
    public function setAuditOnly(array $checks)
    {
        $this->auditOptions = $this->auditOptions->withOnly($checks);
    }

    /**
     * @param bool $strict
     */
    public function setAuditStrictPo($strict)
    {
        $this->auditOptions = $this->auditOptions->withStrictPo((bool) $strict);
    }

    /**
     * @param string[] $formats Raw tokens (e.g. from comma-split CLI); see AuditReportFormat
     */
    public function setAuditReportFormats(array $formats)
    {
        $this->auditOptions = $this->auditOptions->withReportFormats($formats);
    }

    /**
     * @param string|null $dir Absolute or relative directory for audit report files
     */
    public function setAuditReportDir($dir)
    {
        $this->auditOptions = $this->auditOptions->withReportDir($dir !== null ? (string) $dir : null);
    }

    /**
     * Run translation audit checks (CLI --audit).
     *
     * @return bool
     */
    public function audit()
    {
        $start = $this->writeCliBannerAndPluginContext();
        $this->output->phase('Auditing translations');

        $resolved = $this->auditOptions->resolveForRuntime();
        if ($this->auditOptions->isInteractive() && $resolved->isReportOnly()) {
            $this->output->warning(
                'Non-interactive stdin or CI detected — audit mode forced to report (no prompts).'
            );
        }

        $name = $this->getPluginNameForExclusion();
        if ($name === null || $name === '') {
            $name = $this->getPluginSlug();
        }

        try {
            $ok = (new Auditor(
                $this->pluginRoot,
                $this->languagesDir,
                $this->targetLanguages,
                $this->output,
                $this->getApiKey(),
                $this->getPluginVersion(),
                (string) $name,
                $resolved
            ))->run();
            $this->writeCliCompletion($start, $ok);

            return $ok;
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Audit error: ' . $e->getMessage() . "\n");
            $this->writeCliCompletion($start, false);

            return false;
        }
    }

    /**
     * Enable or disable Weblate integration
     *
     * @param bool $enabled
     */
    public function setWeblateEnabled($enabled)
    {
        $this->weblateEnabled = (bool) $enabled;
    }

    private function getTranslatorPackageVersion(): string
    {
        if (!class_exists('\Composer\InstalledVersions')) {
            return 'dev';
        }

        try {
            $v = \Composer\InstalledVersions::getPrettyVersion('publishpress/translations');
            if ($v !== null && $v !== '') {
                return $v;
            }
        } catch (\Throwable $e) {
            // Not listed as installed package (e.g. some path-repo layouts).
        }

        return 'dev';
    }

    /**
     * Print banner, package version, and plugin information block.
     *
     * @return float microtime(true) start for runtime footer
     */
    private function writeCliBannerAndPluginContext(string $bannerTitle = self::CLI_BANNER_TITLE): float
    {
        $start = microtime(true);
        $this->output->banner($bannerTitle);
        $this->output->blankLine();
        $this->output->versionLine('Translator package', $this->getTranslatorPackageVersion());
        $this->output->blankLine();
        $this->output->separator();
        $this->writeCliPluginInformationBlock();

        return $start;
    }

    private function writeCliPluginInformationBlock(): void
    {
        $this->output->blankLine();
        $this->output->sectionHeading('Plugin information:');
        $name = $this->getPluginNameForExclusion();
        if ($name === null || $name === '') {
            $name = $this->getPluginSlug();
        }
        $wpSlugs = $this->getWpOrgPluginSlugs();
        $slugDisplay = !empty($wpSlugs) ? $wpSlugs[0] : $this->getPluginSlug();
        $folder = basename($this->pluginRoot);
        $version = $this->getPluginVersion();
        $this->output->bullet('Name: ' . $name);
        $this->output->bullet('Slug: ' . $slugDisplay);
        $this->output->bullet('Folder: ' . $folder);
        $this->output->bullet('Version: ' . ($version !== null ? $version : 'n/a'));
        $this->output->blankLine();
        $this->output->separator();
    }

    private function writeCliCompletion(float $start, bool $success): void
    {
        $this->output->runtime(microtime(true) - $start);
        if ($success) {
            $this->output->executedSuccessfully();
        } else {
            $this->output->finishedWithErrors();
        }
        $this->output->blankLine();
    }

    /**
     * Repair malformed plural entries in all existing .po files.
     *
     * Scans every .po file in the languages directory for plural entries where
     * msgstr[0] contains pipe-delimited forms and splits them into proper
     * separate msgstr[N] lines. Also regenerates the corresponding .mo files.
     *
     * @return bool
     */
    public function repairPluralEntries()
    {
        $start = $this->writeCliBannerAndPluginContext();
        $this->output->phase('Repairing plural entries in existing .po files');
        $this->output->step('Languages directory: ' . $this->languagesDir);

        $poFiles = glob($this->languagesDir . '/*.po');

        if (empty($poFiles)) {
            fwrite(STDERR, "No .po files found in {$this->languagesDir}\n");
            $this->writeCliCompletion($start, false);

            return false;
        }

        $this->output->step('Found ' . count($poFiles) . ' .po file(s)');

        $repaired = 0;
        foreach ($poFiles as $poFile) {
            $before = file_get_contents($poFile);
            $this->repairPluralPipeDelimitedEntries($poFile);
            $after = file_get_contents($poFile);

            if ($before !== $after) {
                $baseName = basename($poFile);
                $this->output->step('Repaired: ' . $baseName);
                $repaired++;
            }
        }

        $this->output->separator();
        if ($repaired > 0) {
            $this->output->line('Repaired ' . $repaired . ' file(s) with malformed plural entries.');
        } else {
            $this->output->line('No malformed plural entries found — all files are clean.');
        }

        $this->writeCliCompletion($start, true);

        return true;
    }

    /**
     * Clean duplicate entries from all PO files
     *
     * @return bool
     */
    public function cleanPoFiles()
    {
        $start = $this->writeCliBannerAndPluginContext();
        $this->output->phase('Cleaning duplicate entries from .po files');
        $this->output->step('Languages directory: ' . $this->languagesDir);

        $poFiles = glob($this->languagesDir . '/*.po');

        if (empty($poFiles)) {
            fwrite(STDERR, "No .po files found in {$this->languagesDir}\n");
            $this->writeCliCompletion($start, false);

            return false;
        }

        $this->output->step('Found ' . count($poFiles) . ' .po file(s)');

        if (!$this->weblateClient) {
            fwrite(STDERR, "Warning: Weblate client not initialized.\n");
            fwrite(STDERR, "Some cleanup operations may be limited.\n\n");
        }

        $cleaned = 0;

        foreach ($poFiles as $poFile) {
            $baseName = basename($poFile);
            $before = file_get_contents($poFile);

            if ($this->weblateClient) {
                $this->weblateClient->cleanupDuplicatePoHeaders($poFile);
                $this->weblateClient->removeDuplicateReferences($poFile);
                $this->weblateClient->removeDuplicateExtractedComments($poFile);
            }

            $after = file_get_contents($poFile);

            if ($before !== $after) {
                $cleaned++;
                $this->output->step('Cleaned: ' . $baseName);
            } else {
                $this->output->step('Already clean: ' . $baseName);
            }
        }

        $this->output->separator();
        if ($cleaned > 0) {
            $this->output->line('Cleaned ' . $cleaned . ' file(s) with duplicate entries.');
        } else {
            $this->output->line('All .po files are already clean — no duplicates found.');
        }

        $this->writeCliCompletion($start, true);

        return true;
    }

    /**
     * Check .po files and compiled format status (.mo, .json, .l10n.php)
     * This method reports status but doesn't regenerate compiled formats.
     *
     * @return bool
     */
    public function syncPoAndMoFiles()
    {
        $start = $this->writeCliBannerAndPluginContext();
        $this->output->phase('Checking translation file status (.po vs compiled formats)');
        $this->output->step('Languages directory: ' . $this->languagesDir);

        $poFiles = glob($this->languagesDir . '/*.po') ?: [];

        if (empty($poFiles)) {
            fwrite(STDERR, "No .po files found in {$this->languagesDir}\n");
            $this->writeCliCompletion($start, false);

            return false;
        }

        $this->output->step('Found ' . count($poFiles) . ' .po file(s)');

        $missingCompiled = [];

        foreach ($poFiles as $poFile) {
            $baseName = basename($poFile, '.po');
            $poMtime = filemtime($poFile);

            $this->output->line('');
            $this->output->line('  ' . $baseName . '.po');

            // Check .mo file
            $moFile = $this->languagesDir . '/' . $baseName . '.mo';
            if (!file_exists($moFile)) {
                $this->output->line('     ⊘ .mo (missing)');
                $missingCompiled[] = $baseName . '.mo';
            } else {
                $moMtime = filemtime($moFile);
                if ($poMtime > $moMtime) {
                    $this->output->line('     ⚠ .mo (outdated)');
                    $missingCompiled[] = $baseName . '.mo';
                } else {
                    $this->output->line('     ✓ .mo (up-to-date)');
                }
            }

            // Check .json file
            $jsonFile = $this->languagesDir . '/' . $baseName . '.json';
            if (!file_exists($jsonFile)) {
                $this->output->line('     ⊘ .json (missing)');
                $missingCompiled[] = $baseName . '.json';
            } else {
                $jsonMtime = filemtime($jsonFile);
                if ($poMtime > $jsonMtime) {
                    $this->output->line('     ⚠ .json (outdated)');
                    $missingCompiled[] = $baseName . '.json';
                } else {
                    $this->output->line('     ✓ .json (up-to-date)');
                }
            }

            // Check .l10n.php file
            $phpFile = $this->languagesDir . '/' . $baseName . '.l10n.php';
            if (!file_exists($phpFile)) {
                $this->output->line('     ⊘ .l10n.php (missing)');
                $missingCompiled[] = $baseName . '.l10n.php';
            } else {
                $phpMtime = filemtime($phpFile);
                if ($poMtime > $phpMtime) {
                    $this->output->line('     ⚠ .l10n.php (outdated)');
                    $missingCompiled[] = $baseName . '.l10n.php';
                } else {
                    $this->output->line('     ✓ .l10n.php (up-to-date)');
                }
            }
        }

        $this->output->separator();
        if (!empty($missingCompiled)) {
            $this->output->warning('Found ' . count($missingCompiled) . ' file(s) that need compilation:');
            foreach ($missingCompiled as $file) {
                $this->output->line('  - ' . $file);
            }
            $this->output->line('');
            $this->output->line("Run 'composer translate:compile' to compile these files from their .po sources.");
        } else {
            $this->output->line('All translation files are up-to-date.');
        }

        $this->writeCliCompletion($start, true);

        return true;
    }

    /**
     * Reverse-map Weblate language codes to WordPress locale codes
     *
     * @param string $weblateCode
     * @return string
     */
    private function reverseMapWeblateLanguage(string $weblateCode): string
    {
        $code = str_replace('-', '_', $weblateCode);

        // Explicit script → WP locale mapping
        static $scriptMap = [
            'zh_Hans' => 'zh_CN',
            'zh_Hant' => 'zh_TW',
            'yo' => 'yor',
        ];
        if (isset($scriptMap[$code])) {
            return $scriptMap[$code];
        }
        if (strpos($code, '_') !== false) {
            return $code;
        }

        $lang = strtolower($code);

        // Only search for existing regional variants if languages weren't explicitly set.
        // When custom target languages are specified, respect them as-is to avoid creating
        // unintended regional variants
        if (!$this->customTargetLanguages && is_dir($this->languagesDir)) {
            $files = glob($this->languagesDir . "/*-{$lang}_*.po");
            if ($files) {
                if (preg_match('/-(' . preg_quote($lang, '/') . '_[A-Z]{2,})\.po$/', $files[0], $m)) {
                    return $m[1];
                }
            }
        }

        // Languages that WordPress uses without region codes
        $baseLanguages = ['ja', 'fil', 'yor', 'fi', 'ca', 'vi', 'ar', 'el', 'th', 'uk', 'hr'];
        if (in_array($lang, $baseLanguages)) {
            return $lang;
        }

        // Special cases that cannot be derived from "{$lang}_{$langUpper}"
        $specialCases = [
            'ko' => 'ko_KR',
            'nb' => 'nb_NO',
            'nn' => 'nn_NO',
            'sr' => 'sr_RS',
            'he' => 'he_IL',
            'hi' => 'hi_IN',
            'cs' => 'cs_CZ',
            'da' => 'da_DK',
            'sv' => 'sv_SE',
            'sl' => 'sl_SI',
            'et' => 'et_EE',
            'fa' => 'fa_IR',
            'ur' => 'ur_PK',
            'bn' => 'bn_BD',
            'ms' => 'ms_MY',
            'eu' => 'eu_ES',
            'gl' => 'gl_ES',
        ];
        if (isset($specialCases[$lang])) {
            return $specialCases[$lang];
        }

        $langUpper = strtoupper($lang);
        return "{$lang}_{$langUpper}";
    }


    /**
     * Select Weblate languages for download
     *
     * @param array $languageCodes
     * @return array
     */
    private function selectWeblateLanguagesForDownload(array $languageCodes)
    {
        $preferBase = getenv('WEBLATE_PREFER_BASE_LANGUAGE') === 'true' || getenv('WEBLATE_PREFER_BASE_LANGUAGE') === '1';

        $selectedByWpLocale = [];

        foreach ($languageCodes as $code) {
            $code = (string) $code;
            $wpLocale = (string) $this->reverseMapWeblateLanguage($code);

            if (!isset($selectedByWpLocale[$wpLocale])) {
                $selectedByWpLocale[$wpLocale] = $code;
                continue;
            }

            $current = (string) $selectedByWpLocale[$wpLocale];
            $currentIsRegional = strpos($current, '_') !== false;
            $candidateIsRegional = strpos($code, '_') !== false;

            if ($preferBase) {
                if (!$candidateIsRegional && $currentIsRegional) {
                    $selectedByWpLocale[$wpLocale] = $code;
                }
            } else {
                if ($candidateIsRegional && !$currentIsRegional) {
                    $selectedByWpLocale[$wpLocale] = $code;
                }
            }
        }

        return array_values($selectedByWpLocale);
    }

    /**
     * Dedupe Weblate language codes
     *
     * @param array $languageCodes
     * @return array
     */
    private function dedupeWeblateLanguageCodes(array $languageCodes)
    {
        $preferBase = getenv('WEBLATE_PREFER_BASE_LANGUAGE') === 'true' || getenv('WEBLATE_PREFER_BASE_LANGUAGE') === '1';

        $byBase = [];
        foreach ($languageCodes as $code) {
            $normalized = str_replace('-', '_', (string) $code);
            $base = strtolower(explode('_', $normalized, 2)[0]);

            if (!isset($byBase[$base])) {
                $byBase[$base] = [
                    'base' => null,
                    'regional' => [],
                ];
            }

            if (strpos($normalized, '_') === false) {
                $byBase[$base]['base'] = $code;
            } else {
                $byBase[$base]['regional'][] = $code;
            }
        }

        $keep = [];
        foreach ($byBase as $group) {
            $hasBase = $group['base'] !== null;
            $hasRegional = !empty($group['regional']);

            if ($hasBase && $hasRegional) {
                if ($preferBase) {
                    $keep[(string) $group['base']] = true;
                } else {
                    $regional = $group['regional'];
                    sort($regional);
                    $keep[(string) $regional[0]] = true;
                }
                continue;
            }

            if ($hasBase) {
                $keep[(string) $group['base']] = true;
            }

            if ($hasRegional) {
                foreach ($group['regional'] as $regionalCode) {
                    $keep[(string) $regionalCode] = true;
                }
            }
        }

        $result = [];
        foreach ($languageCodes as $code) {
            if (isset($keep[(string) $code])) {
                $result[] = $code;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * Get plugin name from directory
     *
     * @return string
     */
    private function getPluginName()
    {
        return basename($this->pluginRoot);
    }

    /**
     * Get plugin slug from composer.json
     * Falls back to directory name if not found
     *
     * @return string
     */
    private function getPluginSlug()
    {
        $composerFile = $this->pluginRoot . '/composer.json';

        if (file_exists($composerFile)) {
            $composer = json_decode(file_get_contents($composerFile), true);

            if (isset($composer['name'])) {
                $parts = explode('/', $composer['name']);
                return end($parts) ?: 'project';
            }
        }

        // Fallback to directory name
        return basename($this->pluginRoot);
    }

    /**
     * Get plugin name from composer.json extra property
     * Returns the configured plugin name that should not be translated
     *
     * @return string|null Plugin name to exclude, or null if not configured
     */
    private function getPluginNameForExclusion()
    {
        $composerFile = $this->pluginRoot . '/composer.json';

        if (!file_exists($composerFile)) {
            return null;
        }

        $composer = json_decode(file_get_contents($composerFile), true);

        if (isset($composer['extra']['plugin_name'])) {
            return $composer['extra']['plugin_name'];
        }

        return null;
    }

    /**
     * Remove duplicate msgid entries from PO file
     * Keeps only the first occurrence of each msgid to prevent Weblate constraint violations
     *
     * @param string $poFile Path to PO file
     */
    private function deduplicatePoFile($poFile)
    {
        $content = @file_get_contents($poFile);
        if ($content === false || $content === '') {
            return;
        }

        $lines = explode("\n", $content);
        $result = [];
        $seenMsgids = [];
        $i = 0;

        while ($i < count($lines)) {
            $line = $lines[$i];

            if (preg_match('/^msgid\s+"(.*)"$/', $line, $msgidMatch)) {
                $msgid = $msgidMatch[1];

                if (isset($seenMsgids[$msgid])) {
                    $i++;
                    while ($i < count($lines) && !preg_match('/^msgid\s+/', $lines[$i])) {
                        $i++;
                    }
                    continue;
                }

                $seenMsgids[$msgid] = true;
            }

            $result[] = $line;
            $i++;
        }

        file_put_contents($poFile, implode("\n", $result));
    }

    /**
     * Revert plugin name translations in PO file
     * Keeps the plugin name untranslated (msgstr = msgid)
     *
     * @param string $poFile Path to PO file
     */
    private function revertPluginNameTranslations($poFile)
    {
        $pluginName = $this->getPluginNameForExclusion();
        if (!$pluginName) {
            return;
        }

        $content = @file_get_contents($poFile);
        if ($content === false || $content === '') {
            return;
        }

        $lines = explode("\n", $content);
        $result = [];
        $i = 0;

        while ($i < count($lines)) {
            $line = $lines[$i];

            if (preg_match('/^msgid\s+"(.+)"$/', $line, $msgidMatch)) {
                $msgid = $msgidMatch[1];

                if ($msgid === $pluginName && $i + 1 < count($lines)) {
                    $nextLine = $lines[$i + 1];

                    if (preg_match('/^msgstr\s+"(.*)"$/', $nextLine)) {
                        $result[] = $line;

                        $result[] = 'msgstr "' . $msgid . '"';
                        $i += 2;
                        continue;
                    }
                }
            }

            $result[] = $line;
            $i++;
        }

        file_put_contents($poFile, implode("\n", $result));
    }

    /**
     * Load the default dictionaries from config/dictionaries.json.
     *
     * @return array Flattened dictionary
     */
    private function loadDictionaryDefaults()
    {
        $path = dirname(__DIR__) . '/config/dictionaries.json';

        if (!file_exists($path)) {
            return [];
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }

        $flat = [];
        foreach ($data as $entries) {
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $source => $target) {
                if (is_string($source) && is_string($target) && trim($source) !== '') {
                    $flat[$source] = $target;
                }
            }
        }

        return $flat;
    }

    /**
     * Build the translation overrides for all target languages.
     *
     * @return array Two keys
     */
    private function buildTranslationOverrides()
    {
        $global = $this->loadDictionaryDefaults();

        $envGlobal = getenv('TRANSLATION_OVERRIDES');
        if ($envGlobal !== false && trim($envGlobal) !== '') {
            $parsed = TranslationOverrides::parseEnvValue($envGlobal);
            foreach ($parsed as $source => $target) {
                $global[$source] = $target;
            }
        }

        $perLanguage = [];
        foreach ($this->targetLanguages as $lang) {
            $envKey = 'TRANSLATION_OVERRIDES_' . $lang;
            $envVal = getenv($envKey);
            if ($envVal !== false && trim($envVal) !== '') {
                $perLanguage[$lang] = TranslationOverrides::parseEnvValue($envVal);
            }
        }

        return [
            'global' => $global,
            'per_language' => $perLanguage,
        ];
    }

    /**
     * Create a temporary dictionary directory with JSON files that potomatic
     * can consume via --use-dictionary --dictionary-path.
     *
     * @return string|null Path to the temp dictionary dir, or null if no overrides
     */
    private function createTempDictionaryDir()
    {
        $overrides = $this->buildTranslationOverrides();
        $global = $overrides['global'];
        $perLanguage = $overrides['per_language'];

        if (empty($global) && empty($perLanguage)) {
            return null;
        }

        $tmpDir = dirname(__DIR__) . '/translation-overrides-' . uniqid();
        if (!mkdir($tmpDir, 0755, true)) {
            fwrite(STDERR, "Warning: Could not create temporary dictionary directory for overrides: {$tmpDir}\n");
            return null;
        }

        $this->tempDictionaryDir = $tmpDir;

        $wordList = [];

        if (!empty($global)) {
            file_put_contents($tmpDir . '/dictionary.json', json_encode($global, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $wordList = array_merge($wordList, array_keys($global));
        }

        foreach ($perLanguage as $lang => $entries) {
            if (empty($entries)) {
                continue;
            }
            $merged = array_merge($global, $entries);
            $langCode = strtolower(str_replace('_', '-', $lang));
            file_put_contents($tmpDir . '/dictionary-' . $langCode . '.json', json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $wordList = array_merge($wordList, array_keys($entries));
        }

        $wordList = array_unique($wordList);
        $this->output->step(
            'Created temporary translation-overrides directory for: ' . implode(', ', $wordList)
        );

        return $tmpDir;
    }

    /**
     * Remove the temporary dictionary directory created for translation overrides.
     */
    private function cleanupTempDictionaryDir()
    {
        if ($this->tempDictionaryDir === null || !is_dir($this->tempDictionaryDir)) {
            return;
        }

        $files = glob($this->tempDictionaryDir . '/*');
        if ($files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        @rmdir($this->tempDictionaryDir);
        $this->output->step('Deleted temporary translation-overrides directory');
        $this->tempDictionaryDir = null;
    }

    /**
     * Get the overrides map for a specific language.
     *
     * Used for post-processing .po files to enforce overrides after AI translation.
     *
     * @param string $language Target language code
     * @return array Override map
     */
    private function getOverridesForLanguage($language)
    {
        return TranslationOverrides::mapForLanguage($language);
    }

    /**
     * Apply translation overrides to a PO file as post-processing.
     *
     * @param string $poFile   Path to PO file
     * @param string $language Target language code
     */
    private function applyTranslationOverrides($poFile, $language)
    {
        $overrides = $this->getOverridesForLanguage($language);

        if (empty($overrides)) {
            return;
        }

        $content = @file_get_contents($poFile);
        if ($content === false || $content === '') {
            return;
        }

        $lines = explode("\n", $content);
        $result = [];
        $totalReplacements = 0;
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];

            if (preg_match('/^msgid\s+"(.*)"$/', $line, $msgidMatch)) {
                $msgidLines = [$line];
                $msgidValue = $msgidMatch[1];
                $i++;

                while ($i < $count && preg_match('/^"(.*)"$/', $lines[$i], $cont)) {
                    $msgidValue .= $cont[1];
                    $msgidLines[] = $lines[$i];
                    $i++;
                }

                if ($msgidValue === '') {
                    foreach ($msgidLines as $ml) {
                        $result[] = $ml;
                    }
                    continue;
                }

                $matchedOverrides = [];
                foreach ($overrides as $source => $target) {
                    if (strcmp($msgidValue, $source) === 0) {
                        $matchedOverrides[$source] = ['target' => $target, 'exact' => true];
                    } elseif (mb_strpos($msgidValue, $source) !== false) {
                        $matchedOverrides[$source] = ['target' => $target, 'exact' => false];
                    }
                }

                foreach ($msgidLines as $ml) {
                    $result[] = $ml;
                }

                if (empty($matchedOverrides)) {
                    continue;
                }

                while ($i < $count && preg_match('/^msgid_plural\s+"(.*)"$/', $lines[$i])) {
                    $result[] = $lines[$i];
                    $i++;
                    while ($i < $count && preg_match('/^"(.*)"$/', $lines[$i])) {
                        $result[] = $lines[$i];
                        $i++;
                    }
                }

                while ($i < $count && preg_match('/^(msgstr(?:\[\d+\])?)\s+"(.*)"$/', $lines[$i], $msgstrMatch)) {
                    $prefix = $msgstrMatch[1];
                    $msgstrValue = $msgstrMatch[2];
                    $i++;

                    while ($i < $count && preg_match('/^"(.*)"$/', $lines[$i], $cont)) {
                        $msgstrValue .= $cont[1];
                        $i++;
                    }

                    if ($msgstrValue === '') {
                        $result[] = $prefix . ' ""';
                        continue;
                    }

                    $modified = $msgstrValue;
                    $wasModified = false;

                    foreach ($matchedOverrides as $source => $info) {
                        if ($info['exact']) {
                            if ($modified !== $info['target']) {
                                $modified = $info['target'];
                                $wasModified = true;
                            }
                        } else {
                            $escaped = preg_quote($source, '/');
                            if (strpos($source, ' ') !== false) {
                                $pattern = '/' . $escaped . '/u';
                            } else {
                                $pattern = '/\b' . $escaped . '\b/u';
                            }
                            $before = $modified;
                            $modified = preg_replace($pattern, $info['target'], $modified);
                            if ($modified !== $before) {
                                $wasModified = true;
                            }
                        }
                    }

                    if ($wasModified) {
                        $totalReplacements++;
                    }

                    $result[] = $prefix . ' "' . $modified . '"';
                }

                continue;
            }

            $result[] = $line;
            $i++;
        }

        if ($totalReplacements > 0) {
            file_put_contents($poFile, implode("\n", $result));
        }
    }

    /**
     * Get the path to the translation overrides manifest file.
     *
     * The manifest tracks which words were previously set by TRANSLATION_OVERRIDES
     * env vars, so we can detect removed overrides and clear them for re-translation.
     *
     * @return string Path to manifest JSON file
     */
    private function getOverridesManifestPath()
    {
        return dirname(__DIR__) . '/.translation-overrides-manifest.json';
    }

    /**
     * Load the previous overrides manifest.
     *
     * @return array Previous manifest
     */
    private function loadOverridesManifest()
    {
        $path = $this->getOverridesManifestPath();
        if (!file_exists($path)) {
            return [];
        }

        $content = @file_get_contents($path);
        if ($content === false || $content === '') {
            return [];
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Save the current overrides manifest.
     *
     * Records which words are currently being enforced by TRANSLATION_OVERRIDES
     * env vars.
     */
    private function saveOverridesManifest()
    {
        $manifest = $this->loadOverridesManifest();

        foreach ($this->targetLanguages as $lang) {
            $overrides = $this->getOverridesForLanguage($lang);
            if (!empty($overrides)) {
                $manifest[$lang] = array_keys($overrides);
            } else {
                unset($manifest[$lang]);
            }
        }

        $path = $this->getOverridesManifestPath();

        if (empty($manifest)) {
            if (file_exists($path)) {
                @unlink($path);
            }
            return;
        }

        @file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Clear stale overrides from a PO file before running potomatic.
     *
     * @param string $poFile   Path to PO file
     * @param string $language Target language code
     */
    private function clearStaleOverrides($poFile, $language)
    {
        $previousManifest = $this->loadOverridesManifest();

        if (empty($previousManifest)) {
            return;
        }

        $previousWords = [];
        foreach ($previousManifest as $manifestLang => $words) {
            if (strcasecmp($manifestLang, $language) === 0) {
                $previousWords = $words;
                break;
            }
        }

        if (empty($previousWords)) {
            return;
        }

        $currentOverrides = $this->getOverridesForLanguage($language);

        $staleWords = [];
        foreach ($previousWords as $word) {
            $stillActive = false;
            foreach ($currentOverrides as $source => $target) {
                if (strcasecmp($word, $source) === 0) {
                    $stillActive = true;
                    break;
                }
            }
            if (!$stillActive) {
                $staleWords[] = $word;
            }
        }

        if (empty($staleWords)) {
            return;
        }

        $content = @file_get_contents($poFile);
        if ($content === false || $content === '') {
            return;
        }

        $lines = explode("\n", $content);
        $result = [];
        $totalCleared = 0;
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = rtrim($lines[$i], "\r");

            if (preg_match('/^msgid\s+"(.*)"$/', $line, $msgidMatch)) {
                $msgidLines = [$line];
                $msgidValue = $msgidMatch[1];
                $i++;

                while ($i < $count && preg_match('/^"(.*)"$/', rtrim($lines[$i], "\r"), $cont)) {
                    $msgidValue .= $cont[1];
                    $msgidLines[] = rtrim($lines[$i], "\r");
                    $i++;
                }

                foreach ($msgidLines as $ml) {
                    $result[] = $ml;
                }

                if ($msgidValue === '') {
                    continue;
                }


                $isStale = false;
                foreach ($staleWords as $staleWord) {
                    if (strcasecmp($msgidValue, $staleWord) === 0) {
                        $isStale = true;
                        break;
                    }
                }

                if ($isStale && $i < $count && preg_match('/^msgstr\s+"(.*)"$/', rtrim($lines[$i], "\r"), $msgstrMatch)) {
                    $msgstrValue = $msgstrMatch[1];
                    $i++;

                    while ($i < $count && preg_match('/^"(.*)"$/', rtrim($lines[$i], "\r"), $cont)) {
                        $msgstrValue .= $cont[1];
                        $i++;
                    }

                    if ($msgstrValue === $msgidValue) {
                        $result[] = 'msgstr ""';
                        $totalCleared++;
                        $this->output->step("Clearing stale override '{$msgidValue}' for re-translation");
                    } else {
                        $result[] = 'msgstr "' . $msgstrValue . '"';
                    }

                    continue;
                }

                continue;
            }

            $result[] = rtrim($line, "\r");
            $i++;
        }

        if ($totalCleared > 0) {
            file_put_contents($poFile, implode("\n", $result));
            $this->output->step("Cleared {$totalCleared} stale override(s) in {$language} for re-translation");
        }
    }

    /**
     * Extract language code from a PO file path.
     *
     * @param string $poFile     Path to PO file
     * @param string $textDomain Text domain prefix
     * @return string|null Language code or null if not extractable
     */
    private function extractLanguageFromPoFile($poFile, $textDomain)
    {
        $baseName = basename($poFile, '.po');
        $prefix = $textDomain . '-';

        if (strpos($baseName, $prefix) === 0) {
            return substr($baseName, strlen($prefix));
        }

        return null;
    }

    /**
     * Repair plural entries where msgstr entries contain pipe-delimited forms.
     *
     * Handles cases where the AI generated malformed plurals like:
     * msgstr[0] "singular|plural"
     * msgstr[1] "plural|plural"
     *
     * @param string $poFile Path to PO file
     */
    private function repairPluralPipeDelimitedEntries($poFile)
    {
        $content = @file_get_contents($poFile);
        if ($content === false || $content === '') {
            return;
        }

        $lines = explode("\n", $content);
        $result = [];
        $count = count($lines);
        $modified = false;

        for ($i = 0; $i < $count; $i++) {
            // Remove carriage returns to handle both Unix (LF) and Windows (CRLF) line endings
            $line = rtrim($lines[$i], "\r");

            if (preg_match('/^msgid_plural\s+"(.*)"$/', $line)) {
                $result[] = $line;
                $i++;

                $msgstrEntries = [];
                $msgstrRawLines = [];

                // Collect all msgstr[n] entries, handling multiline strings properly
                while ($i < $count && preg_match('/^msgstr\[(\d+)\]/', rtrim($lines[$i], "\r"), $m)) {
                    $idx = (int)$m[1];
                    $rawLineOriginal = rtrim($lines[$i], "\r");
                    $rawLines = [$rawLineOriginal];
                    $i++;

                    // Extract the initial value on the msgstr[n] line
                    if (preg_match('/^msgstr\[\d+\]\s+"(.*)"$/', $rawLineOriginal, $match)) {
                        $value = $match[1];
                    } else {
                        $value = '';
                    }

                    while ($i < $count && preg_match('/^"(.*)"$/', rtrim($lines[$i], "\r"), $cont)) {
                        $value .= $cont[1];
                        $rawLines[] = rtrim($lines[$i], "\r");
                        $i++;
                    }

                    $msgstrEntries[$idx] = $value;
                    $msgstrRawLines[$idx] = $rawLines;
                }

                // Check if ANY msgstr entry contains pipe-delimited forms
                $hasPipedEntry = false;
                $sourcePipeEntry = null;

                foreach ($msgstrEntries as $idx => $value) {
                    if (strpos($value, '|') !== false) {
                        $hasPipedEntry = true;
                        // Use the first piped entry as the source for splitting
                        if ($sourcePipeEntry === null) {
                            $sourcePipeEntry = $value;
                        }
                        break;
                    }
                }

                if ($hasPipedEntry && $sourcePipeEntry !== null) {
                    $forms = array_map('trim', explode('|', $sourcePipeEntry));

                    $nplurals = max(count($msgstrEntries), count($forms));

                    for ($formIdx = 0; $formIdx < $nplurals; $formIdx++) {
                        $formValue = $forms[$formIdx] ?? '';
                        $formValue = addcslashes($formValue, '"\\');
                        $result[] = 'msgstr[' . $formIdx . '] "' . $formValue . '"';
                    }
                    $modified = true;
                } else {
                    foreach ($msgstrRawLines as $rawLines) {
                        foreach ($rawLines as $rawLine) {
                            $result[] = $rawLine;
                        }
                    }
                }

                $i--;
                continue;
            }

            $result[] = $line;
        }

        if ($modified) {
            file_put_contents($poFile, implode("\n", $result));
        }
    }

    /**
     * Validate PO file for pipe-delimited plural entries
     * Logs warnings if malformed entries are detected
     *
     * @param string $poFile Path to PO file to validate
     * @return bool True if file is clean, false if issues found
     */
    private function validatePluralEntries($poFile)
    {
        $content = @file_get_contents($poFile);
        if ($content === false || $content === '') {
            return true;
        }

        $lines = explode("\n", $content);
        $issues = 0;
        $lineNum = 0;

        for ($i = 0; $i < count($lines); $i++) {
            $line = rtrim($lines[$i], "\r");
            $lineNum++;

            if (preg_match('/^msgstr\[\d+\]\s+"(.*)"/', $line, $match)) {
                $value = $match[1];

                while ($i + 1 < count($lines) && preg_match('/^"(.*)"/', rtrim($lines[$i + 1], "\r"), $cont)) {
                    $value .= $cont[1];
                    $i++;
                }

                if (strpos($value, '|') !== false) {
                    $this->output->warning(
                        'WARNING: Pipe-delimited msgstr at line ' . $lineNum . ': ' . substr($value, 0, 80) . '...'
                    );
                    $issues++;
                }
            }
        }

        if ($issues > 0) {
            $this->output->warning("Found {$issues} malformed plural entries. Running repair...");
            $this->repairPluralPipeDelimitedEntries($poFile);
            return false;
        }

        return true;
    }


    /**
     * Get possible WordPress.org plugin slugs from composer.json extra field.
     *
     * Returns an array of slugs to try, in priority order.
     *
     * @return array Array of possible plugin slugs for wordpress.org
     */
    private function getWpOrgPluginSlugs()
    {
        $slugs = [];
        $composerFile = $this->pluginRoot . '/composer.json';

        if (file_exists($composerFile)) {
            $composer = json_decode(file_get_contents($composerFile), true);

            // Try plugin-slug first
            if (isset($composer['extra']['plugin-slug'])) {
                $slugs[] = $composer['extra']['plugin-slug'];
            }

            // Also try plugin-lang-domain as it might be the actual wp.org slug
            if (
                isset($composer['extra']['plugin-lang-domain']) &&
                !in_array($composer['extra']['plugin-lang-domain'], $slugs)
            ) {
                $slugs[] = $composer['extra']['plugin-lang-domain'];
            }
        }

        return $slugs;
    }

    /**
     * Get the plugin version from the main plugin file header.
     *
     * Scans PHP files in the plugin root for the standard WordPress "Version:" header.
     *
     * @return string|null Plugin version or null if not found
     */
    private function getPluginVersion()
    {
        $phpFiles = glob($this->pluginRoot . '/*.php');

        foreach ($phpFiles as $phpFile) {
            $content = @file_get_contents($phpFile, false, null, 0, 8192);
            if ($content === false) {
                continue;
            }

            if (preg_match('/^\s*\*?\s*Version:\s*(.+)$/mi', $content, $match)) {
                return trim($match[1]);
            }
        }

        return null;
    }

    /**
     * Download translations from translate.wordpress.org for a given text domain.
     *
     * @param string $textDomain The plugin text domain
     * @param bool   $silent     If true, suppress output messages
     * @return int Number of languages downloaded
     */
    private function downloadFromWordPressOrg($textDomain, $silent = false)
    {
        $possibleSlugs = $this->getWpOrgPluginSlugs();
        if (empty($possibleSlugs)) {
            if (!$silent) {
                fwrite(STDERR, "  Warning: Cannot determine wordpress.org plugin slug. Skipping wp.org download.\n");
            }
            return 0;
        }

        $pluginVersion = $this->getPluginVersion();
        $context = stream_context_create([
            'http' => [
                'timeout' => 60,
                'user_agent' => 'PublishPress-Translations/1.0',
            ],
        ]);

        // Try each possible slug until we find translations
        $data = null;

        foreach ($possibleSlugs as $index => $slug) {
            $apiUrl = 'https://api.wordpress.org/translations/plugins/1.0/';
            $apiUrl .= '?slug=' . urlencode($slug);
            if ($pluginVersion) {
                $apiUrl .= '&version=' . urlencode($pluginVersion);
            }

            if (!$silent) {
                if ($index === 0) {
                    $this->output->step("Fetching available translations from wordpress.org for '{$slug}'");
                } else {
                    $this->output->step("No translations found for previous slug, trying '{$slug}' (plugin-lang-domain)");
                }
            }

            $response = @file_get_contents($apiUrl, false, $context);
            if ($response !== false) {
                $decoded = json_decode($response, true);
                if (is_array($decoded) && isset($decoded['translations']) && !empty($decoded['translations'])) {
                    $data = $decoded;
                    break;
                }
            }
        }

        if ($data === null) {
            if (!$silent) {
                $this->output->step('No translations available on wordpress.org for any of: ' . implode(', ', $possibleSlugs));
            }
            return 0;
        }

        $availableTranslations = [];
        foreach ($data['translations'] as $translation) {
            if (isset($translation['language']) && isset($translation['package'])) {
                $availableTranslations[$translation['language']] = $translation;
            }
        }

        $allSupportedLanguages = array_unique(array_merge($this->targetLanguages, $this->skippedLanguages));

        if (!$silent) {
            $this->output->step('Found ' . count($availableTranslations) . ' language(s) on wordpress.org');
            $this->output->step('Available wp.org languages: ' . implode(', ', array_keys($availableTranslations)));
            $this->output->step('Checking against all supported languages: ' . implode(', ', $allSupportedLanguages));
        }

        $downloaded = 0;
        $tmpDir = sys_get_temp_dir() . '/pp-wporg-translations-' . uniqid();
        @mkdir($tmpDir, 0755, true);

        foreach ($allSupportedLanguages as $language) {
            if (!isset($availableTranslations[$language])) {
                if (!$silent) {
                    $this->output->step("Skipping {$language} (not available on wp.org)");
                }
                continue;
            }

            $packageUrl = $availableTranslations[$language]['package'];
            if (empty($packageUrl)) {
                continue;
            }

            $zipContent = @file_get_contents($packageUrl, false, $context);
            if ($zipContent === false) {
                if (!$silent) {
                    fwrite(STDERR, "    Warning: Failed to download {$language} package from wordpress.org\n");
                }
                continue;
            }

            $zipFile = $tmpDir . '/' . $language . '.zip';
            file_put_contents($zipFile, $zipContent);

            $zip = new \ZipArchive();
            if ($zip->open($zipFile) !== true) {
                if (!$silent) {
                    fwrite(STDERR, "    Warning: Failed to open zip for {$language}\n");
                }
                @unlink($zipFile);
                continue;
            }

            $poExtracted = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);
                if (substr($entryName, -3) === '.po') {
                    $poContent = $zip->getFromIndex($i);
                    if ($poContent !== false) {
                        $targetPoFile = $this->languagesDir . '/' . $textDomain . '-' . $language . '.po';
                        $merged = $this->mergeWpOrgTranslations($targetPoFile, $poContent, $language, $silent);
                        $poExtracted = true;
                        if ($merged) {
                            $downloaded++;
                        }
                    }
                    break;
                }
            }

            $zip->close();
            @unlink($zipFile);

            if (!$poExtracted && !$silent) {
                $this->output->step("No PO file found in {$language} package");
            }
        }

        $tmpFiles = glob($tmpDir . '/*');
        if ($tmpFiles) {
            foreach ($tmpFiles as $f) {
                @unlink($f);
            }
        }
        @rmdir($tmpDir);

        return $downloaded;
    }

    /**
     * Merge translations from wordpress.org into an existing PO file.
     *
     * @param string $targetPoFile    Path to the target PO file
     * @param string $wpOrgPoContent  Raw PO content downloaded from wordpress.org
     * @param string $language        Language code
     * @param bool   $silent          Suppress output
     */
    private function mergeWpOrgTranslations($targetPoFile, $wpOrgPoContent, $language, $silent = false)
    {
        $wpOrgMap = $this->parsePoContentToMap($wpOrgPoContent);

        if (empty($wpOrgMap)) {
            return '';
        }

        if (!file_exists($targetPoFile)) {
            if (!$silent) {
                $this->output->step("Skipping {$language} (file doesn't exist locally)");
            }
            return false;
        }

        $existingContent = @file_get_contents($targetPoFile);
        if ($existingContent === false || $existingContent === '') {
            if (!$silent) {
                $this->output->step("Skipping {$language} (file is empty)");
            }
            return false;
        }

        $lines = explode("\n", $existingContent);
        $result = [];
        $overridden = 0;
        $filled = 0;
        $unchanged = 0;
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];

            if (preg_match('/^msgid\s+"(.*)"$/', $line, $msgidMatch)) {
                $msgidLines = [$line];
                $msgidValue = $msgidMatch[1];
                $i++;

                while ($i < $count && preg_match('/^"(.*)"$/', $lines[$i], $cont)) {
                    $msgidValue .= $cont[1];
                    $msgidLines[] = $lines[$i];
                    $i++;
                }

                foreach ($msgidLines as $ml) {
                    $result[] = $ml;
                }

                if ($msgidValue === '') {
                    continue;
                }

                if ($i < $count && preg_match('/^msgid_plural\s+/', $lines[$i])) {
                    continue;
                }

                if ($i < $count && preg_match('/^msgstr\s+"(.*)"$/', $lines[$i], $msgstrMatch)) {
                    $msgstrValue = $msgstrMatch[1];
                    $msgstrLineIdx = $i;
                    $i++;

                    while ($i < $count && preg_match('/^"(.*)"$/', $lines[$i], $cont)) {
                        $msgstrValue .= $cont[1];
                        $i++;
                    }

                    if (isset($wpOrgMap[$msgidValue]) && $wpOrgMap[$msgidValue] !== '') {
                        $wpOrgTranslation = $wpOrgMap[$msgidValue];
                        $result[] = 'msgstr "' . $wpOrgTranslation . '"';

                        if ($msgstrValue === '') {
                            $filled++;
                        } elseif ($msgstrValue !== $wpOrgTranslation) {
                            $overridden++;
                        } else {
                            $unchanged++;
                        }
                    } else {
                        for ($j = $msgstrLineIdx; $j < $i; $j++) {
                            $result[] = $lines[$j];
                        }
                    }

                    continue;
                }

                continue;
            }
            $result[] = $line;
            $i++;
        }

        if (count($wpOrgMap) > 0) {
            file_put_contents($targetPoFile, implode("\n", $result));
        }

        if ($overridden === 0 && $filled === 0 && $unchanged === 0) {
            if (!$silent) {
                $this->output->step("Checking {$language} (no matching strings found - different POT structure)");
            }
            return false;
        }

        if (!$silent && (count($wpOrgMap) > 0)) {
            $parts = [];
            if ($filled > 0) {
                $parts[] = "{$filled} updated from wp.org translations";
            }
            if ($overridden > 0) {
                $parts[] = "{$overridden} corrected from wp.org translations";
            }
            if ($unchanged > 0) {
                $parts[] = "{$unchanged} already in sync with wp.org translations";
            }
            if (!empty($parts)) {
                $this->output->step("Merged wp.org translations for {$language} (" . implode(', ', $parts) . ')');
                return true;
            }
        }
        return false;
    }

    /**
     * Parse PO file content into a simple msgid to msgstr map.
     *
     * Only handles single-form (non-plural) entries.
     *
     * @param string $poContent Raw PO file content
     * @return array Map of msgid to msgstr
     */
    private function parsePoContentToMap($poContent)
    {
        $map = [];
        $lines = explode("\n", $poContent);
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = rtrim($lines[$i], "\r");

            if (preg_match('/^msgid\s+"(.*)"$/', $line, $msgidMatch)) {
                $msgidValue = $msgidMatch[1];
                $i++;

                while ($i < $count && preg_match('/^"(.*)"$/', rtrim($lines[$i], "\r"), $cont)) {
                    $msgidValue .= $cont[1];
                    $i++;
                }

                if ($msgidValue === '') {
                    continue;
                }

                if ($i < $count && preg_match('/^msgid_plural\s+/', rtrim($lines[$i], "\r"))) {
                    continue;
                }

                if ($i < $count && preg_match('/^msgstr\s+"(.*)"$/', rtrim($lines[$i], "\r"), $msgstrMatch)) {
                    $msgstrValue = $msgstrMatch[1];
                    $i++;

                    while ($i < $count && preg_match('/^"(.*)"$/', rtrim($lines[$i], "\r"), $cont)) {
                        $msgstrValue .= $cont[1];
                        $i++;
                    }

                    if ($msgstrValue !== '') {
                        $map[$msgidValue] = $msgstrValue;
                    }
                }

                continue;
            }

            $i++;
        }

        return $map;
    }


    /**
     * Find all POT files
     *
     * @return array
     */
    private function findPotFiles()
    {
        $potFiles = [];

        if (!is_dir($this->languagesDir)) {
            return $potFiles;
        }

        $files = scandir($this->languagesDir);
        foreach ($files as $file) {
            if (substr($file, -4) === '.pot') {
                $potFiles[] = $this->languagesDir . '/' . $file;
            }
        }

        return $potFiles;
    }

    /**
     * Get Potomatic executable path
     *
     * @return string
     * @throws Exception
     */
    private function getPotomaticPath()
    {
        $isDevWorkspace = $this->isDevWorkspace();

        $possiblePaths = [];

        if ($isDevWorkspace) {
            $possiblePaths[] = $this->pluginRoot . '/lib/vendor/publishpress/translations/potomatic/potomatic.js';
            $possiblePaths[] = $this->pluginRoot . '/vendor/publishpress/translations/potomatic/potomatic.js';
        } else {
            $possiblePaths[] = $this->pluginRoot . '/vendor/publishpress/translations/potomatic/potomatic.js';
            $possiblePaths[] = $this->pluginRoot . '/lib/vendor/publishpress/translations/potomatic/potomatic.js';
        }

        // Always check library's own potomatic (for development)
        $possiblePaths[] = __DIR__ . '/../potomatic/potomatic.js';

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                if (!is_executable($path)) {
                    @chmod($path, 0755);
                }

                return $path;
            }
        }

        $message = "Potomatic not found.\n\n";
        $message .= "Environment: " . ($isDevWorkspace ? "dev-workspace (Docker)" : "plugin root") . "\n";
        $message .= "Searched in:\n";
        foreach ($possiblePaths as $path) {
            $message .= "  - $path\n";
        }
        $message .= "\nPlease ensure the library was installed correctly via Composer.\n";

        throw new Exception($message);
    }

    /**
     * Detect if running in dev-workspace environment
     *
     * @return bool
     */
    private function isDevWorkspace()
    {
        $indicators = [
            getenv('DOCKER_CONTAINER') !== false,
            getenv('CONTAINER') !== false,
            strpos($this->pluginRoot, 'project') !== false,
            file_exists($this->pluginRoot . '/lib/composer.json'),
            is_dir($this->pluginRoot . '/dev-workspace'),
        ];

        return in_array(true, $indicators, true);
    }

    /**
     * Get OpenAI API key
     *
     * @return string|null
     */
    private function getApiKey()
    {
        return getenv('OPENAI_API_KEY') ?: null;
    }

    /**
     * Build Potomatic command
     *
     * @param string $potFile
     * @param string $textDomain
     * @return string
     */
    private function buildCommand($potFile, $textDomain)
    {
        $potomatic = $this->getPotomaticPath();

        // On Windows, we need to run with node explicitly
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        if ($isWindows) {
            $cmd = 'node ' . escapeshellarg($potomatic);
        } else {
            $cmd = escapeshellarg($potomatic);
        }

        $cmd .= ' --target-languages ' . escapeshellarg(implode(',', $this->targetLanguages));
        $cmd .= ' --pot-file-path ' . escapeshellarg($potFile);
        $cmd .= ' --output-dir ' . escapeshellarg($this->languagesDir);
        $cmd .= ' --po-file-prefix ' . escapeshellarg($textDomain . '-');
        $cmd .= ' --model ' . escapeshellarg($this->potomaticSettings['model']);
        $cmd .= ' --batch-size ' . (int) $this->potomaticSettings['batch_size'];
        $cmd .= ' --jobs ' . (int) $this->potomaticSettings['jobs'];
        $cmd .= ' --max-cost ' . (float) $this->potomaticSettings['max_cost'];
        $cmd .= ' --verbose-level ' . (int) $this->potomaticSettings['verbose_level'];

        if ($this->forceTranslate) {
            $cmd .= ' --force-translate';
        }

        if ($this->dryRun) {
            $cmd .= ' --dry-run';
        }

        $apiKey = $this->getApiKey();
        if ($apiKey) {
            $cmd .= ' --api-key ' . escapeshellarg($apiKey);
        }

        if ($this->tempDictionaryDir !== null && is_dir($this->tempDictionaryDir)) {
            $cmd .= ' --use-dictionary';
            $cmd .= ' --dictionary-path ' . escapeshellarg($this->tempDictionaryDir);
        }

        return $cmd;
    }

    /**
     * Upload translations to Weblate (internal method)
     *
     * @param string $potFile
     * @param string $textDomain
     * @throws Exception
     */
    private function uploadToWeblateInternal($potFile, $textDomain)
    {
        if (!$this->weblateClient) {
            throw new Exception('Weblate client not initialized');
        }

        $this->output->separator();
        $this->output->phase('Uploading to Weblate');

        $pluginSlug   = $this->getPluginSlug();
        $projectSlug  = $this->getWeblateProjectSlug();
        $componentSlug = $this->getWeblateComponentSlug($textDomain);

        // Step 1: Ensure project exists
        $this->output->step("Checking project '{$projectSlug}'");
        if (!$this->weblateClient->projectExists($projectSlug)) {
            $this->output->step("Creating project '{$projectSlug}'");
            $this->weblateClient->createProject($projectSlug, $pluginSlug, $this->getGitRepoUrl());
        }

        // Step 2: Ensure component exists, auto-create if needed
        $this->output->step("Checking component '{$componentSlug}'");

        if (!$this->weblateClient->componentExists($projectSlug, $componentSlug)) {
            $this->output->step("Creating component '{$componentSlug}'");
            try {
                $this->weblateClient->createComponent(
                    $projectSlug,
                    $componentSlug,
                    $textDomain,
                    $potFile,
                    $this->getGitRepoUrl()
                );
                $this->output->step('Component created successfully');
            } catch (Exception $e) {
                $message = $e->getMessage();

                if (strpos($message, 'Your push URL seems to miss credentials') !== false) {
                    $message .= "\n\n";
                    $message .= "This usually happens when using a private Git repository without an authenticated URL.\n";
                    $message .= "You can fix this by configuring Weblate repo URLs via environment variables, for example:\n";
                    $message .= "  - Set WEBLATE_REPO_URL to a credentialed HTTPS or SSH URL (e.g. git@github.com:repository/repository-name.git)\n";
                    $message .= "  - Optionally set WEBLATE_PUSH_URL if push should differ from repo\n";
                    $message .= "  - Or set WEBLATE_REPO_TYPE=ssh and configure an SSH key for this repo in Weblate\n";
                    $message .= "  - Or set WEBLATE_SKIP_VCS=true to create components without VCS integration\n";
                }

                throw new Exception("Failed to create component: " . $message);
            }
        }

        $this->output->step('Uploading POT file (source strings)');
        try {
            $this->weblateClient->uploadPot($projectSlug, $componentSlug, $potFile);
            $this->output->step('POT file uploaded');
        } catch (Exception $e) {
            $this->output->warning('POT upload failed: ' . $e->getMessage());
            $this->output->step('Continuing with PO file uploads');
        }

        // Step 3: Upload PO files
        $this->output->step('Uploading translation files');

        // Get all PO files
        $allPoFiles = glob($this->languagesDir . "/{$componentSlug}-*.po");

        // Filter by target languages if custom languages were specified, and always exclude skipped languages
        $poFilesToUpload = [];
        if ($this->customTargetLanguages) {
            foreach ($allPoFiles as $poFile) {
                preg_match("/{$componentSlug}-(.+)\.po$/", basename($poFile), $matches);
                if (isset($matches[1]) && in_array($matches[1], $this->targetLanguages) && !in_array($matches[1], $this->skippedLanguages)) {
                    $poFilesToUpload[] = $poFile;
                }
            }

            if (empty($poFilesToUpload)) {
                $this->output->warning('No PO files found matching specified languages: ' . implode(', ', $this->targetLanguages));
                return;
            }

            $this->output->step('Uploading ' . count($poFilesToUpload) . ' language(s): ' . implode(', ', $this->targetLanguages));
        } else {
            // Filter out skipped languages from all PO files
            foreach ($allPoFiles as $poFile) {
                preg_match("/{$componentSlug}-(.+)\.po$/", basename($poFile), $matches);
                if (isset($matches[1]) && !in_array($matches[1], $this->skippedLanguages)) {
                    $poFilesToUpload[] = $poFile;
                }
            }
        }

        $uploadedCount = 0;
        $failedCount = 0;
        $delayBetweenUploads = (int) (getenv('WEBLATE_UPLOAD_DELAY') ?: 2);

        foreach ($poFilesToUpload as $index => $poFile) {
            preg_match("/{$componentSlug}-(.+)\.po$/", basename($poFile), $matches);
            if (!isset($matches[1])) {
                continue;
            }

            $languageCode = $matches[1];

            $this->output->step("Preparing {$languageCode}");

            // Validate no malformed plural entries before uploading
            $this->validatePluralEntries($poFile);

            $uploaded = false;
            $maxRetries = 3;

            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                try {
                    $this->weblateClient->ensureTranslation($projectSlug, $componentSlug, $languageCode);
                    $this->weblateClient->uploadPo($projectSlug, $componentSlug, $languageCode, $poFile);
                    $this->output->step("Uploaded {$languageCode}");
                    $uploadedCount++;
                    $uploaded = true;
                    break;
                } catch (Exception $e) {
                    $is503 = strpos($e->getMessage(), '503') !== false || strpos($e->getMessage(), 'Service Unavailable') !== false;
                    $isTlsError = strpos($e->getMessage(), 'cURL error 56') !== false || strpos($e->getMessage(), 'SSL') !== false;

                    if (
                        strpos($e->getMessage(), 'read-only') !== false &&
                        in_array($languageCode, ['en', 'en_US', 'en_GB'])
                    ) {
                        $this->output->step("{$languageCode} (source language, read-only)");
                        $uploaded = true;
                        break;
                    }

                    // Check for duplicate constraint error
                    if (
                        strpos($e->getMessage(), 'duplicate key value violates unique constraint') !== false ||
                        strpos($e->getMessage(), 'trans_unit_translation_id_id_hash') !== false
                    ) {
                        $this->output->warning('Duplicate entries detected, cleaning PO file');
                        $this->deduplicatePoFile($poFile);

                        if ($attempt < $maxRetries) {
                            $this->output->step('Retrying upload after cleanup');
                            sleep(2);
                            continue;
                        }
                    }

                    if (($is503 || $isTlsError) && $attempt < $maxRetries) {
                        $backoffDelay = $attempt * 10;
                        $this->output->step("Retrying {$languageCode} after {$backoffDelay}s (attempt {$attempt}/{$maxRetries})");
                        sleep($backoffDelay);
                        continue;
                    }

                    fwrite(STDERR, "    ⚠️  Failed to upload {$languageCode}: " . $e->getMessage() . "\n");
                    $failedCount++;
                    break;
                }
            }

            if ($uploaded && $index < count($poFilesToUpload) - 1 && $delayBetweenUploads > 0) {
                sleep($delayBetweenUploads);
            }
        }

        if ($failedCount > 0) {
            fwrite(STDERR, "  ⚠️  {$uploadedCount} uploaded, {$failedCount} failed\n");
        } else {
            $this->output->step('All translations uploaded');
        }

        $this->output->step('View at: https://weblate.publishpress.com/projects/' . $projectSlug . '/' . $componentSlug . '/');
        $this->output->blankLine();
    }

    /**
     * Get GitHub repo slug from plugin root
     *
     * @return string|null
     */
    private function getGitRepoSlug()
    {
        $gitDir = $this->pluginRoot . '/.git';
        if (!is_dir($gitDir)) {
            return null;
        }

        $configFile = $gitDir . '/config';
        if (file_exists($configFile)) {
            $content = file_get_contents($configFile);
            if (preg_match('/url\s*=\s*.*publishpress\/(.+?)(\.git)?$/m', $content, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Get GitHub repo URL from plugin root
     *
     * @return string|null
     */
    private function getGitRepoUrl()
    {
        $gitDir = $this->pluginRoot . '/.git';
        if (!is_dir($gitDir)) {
            return null;
        }

        $configFile = $gitDir . '/config';
        if (file_exists($configFile)) {
            $content = file_get_contents($configFile);
            if (preg_match('/url\s*=\s*(.+?)(\.git)?$/m', $content, $matches)) {
                $url = $matches[1];
                if (strpos($url, 'git@github.com:') === 0) {
                    $url = str_replace('git@github.com:', 'https://github.com/', $url);
                }
                if (!str_ends_with($url, '.git')) {
                    $url .= '.git';
                }
                return $url;
            }
        }

        return null;
    }

    /**
     * Upload existing translations to Weblate (public method)
     *
     * @return bool
     */
    public function uploadToWeblate()
    {
        if (!$this->weblateClient) {
            fwrite(STDERR, "Error: Weblate not configured.\n");
            fwrite(STDERR, "Please set WEBLATE_API_TOKEN environment variable.\n\n");
            return false;
        }

        $pluginSlug = $this->getPluginSlug();
        $start = $this->writeCliBannerAndPluginContext();

        $potFiles = $this->findPotFiles();

        if (empty($potFiles)) {
            fwrite(STDERR, "Error: No .pot files found in {$this->languagesDir}\n");
            $this->writeCliCompletion($start, false);

            return false;
        }

        $this->output->phase('Uploading translations to Weblate');
        $this->output->step('POT files found: ' . count($potFiles));

        $success = true;
        foreach ($potFiles as $potFile) {
            $potFileName = basename($potFile);
            $textDomain = str_replace('.pot', '', $potFileName);

            $this->output->separator();
            $this->output->step('Component POT: ' . basename($potFile));

            try {
                $this->uploadToWeblateInternal($potFile, $textDomain);
            } catch (Exception $e) {
                fwrite(STDERR, "⚠️  Warning: Weblate upload failed for {$textDomain}: " . $e->getMessage() . "\n\n");
                $success = false;
            }
        }

        $this->output->separator();
        $this->output->line('Upload ' . ($success ? 'complete' : 'finished with errors') . " for {$pluginSlug}.");
        $this->writeCliCompletion($start, $success);

        return $success;
    }

    /**
     * Get Weblate project slug from composer.json or config
     *
     * @return string
     */
    private function getWeblateProjectSlug()
    {
        $projectSlug = getenv('WEBLATE_PROJECT_SLUG');
        if ($projectSlug) {
            return $projectSlug;
        }

        return $this->getPluginSlug();
    }

    /**
     * Get Weblate component slug from environment or text domain
     *
     * @param string $textDomain
     * @return string
     */
    private function getWeblateComponentSlug($textDomain)
    {
        $componentSlug = getenv('WEBLATE_COMPONENT_SLUG');
        if ($componentSlug) {
            return $componentSlug;
        }

        return $textDomain;
    }

    /**
     * Download translations from Weblate
     *
     * @param bool $silent If true, suppress output messages
     * @return bool
     */
    public function downloadFromWeblate($silent = false)
    {
        if (!$this->weblateClient) {
            if (!$silent) {
                fwrite(STDERR, "Error: Weblate not configured.\n");
                fwrite(STDERR, "Please set WEBLATE_API_TOKEN environment variable.\n\n");
            }
            return false;
        }

        $projectSlug = $this->getWeblateProjectSlug();
        $start = null;

        if (!$silent) {
            $start = $this->writeCliBannerAndPluginContext();
            $this->output->phase('Downloading translations from Weblate');
            $this->output->step('Weblate project: ' . $projectSlug);
        }

        $potFiles = $this->findPotFiles();

        if (empty($potFiles)) {
            if (!$silent) {
                fwrite(STDERR, "Error: No .pot files found in {$this->languagesDir}\n");
                if ($start !== null) {
                    $this->writeCliCompletion($start, false);
                }
            }
            return false;
        }

        if (!$silent) {
            $this->output->step('POT files found: ' . count($potFiles));
            $this->output->blankLine();
        }

        $success = true;
        $totalDownloaded = 0;

        foreach ($potFiles as $potFile) {
            $potFileName = basename($potFile);
            $textDomain = str_replace('.pot', '', $potFileName);
            $componentSlug = $this->getWeblateComponentSlug($textDomain);

            if (!$silent) {
                $this->output->separator();
                $this->output->step('Weblate component: ' . $componentSlug);
            }

            $cleanExisting = getenv('WEBLATE_CLEAN_EXISTING_TRANSLATIONS') === 'true' || getenv('WEBLATE_CLEAN_EXISTING_TRANSLATIONS') === '1';
            if ($cleanExisting) {
                foreach (glob($this->languagesDir . "/{$textDomain}-*.po") as $existingPo) {
                    @unlink($existingPo);
                }
                foreach (glob($this->languagesDir . "/{$textDomain}-*.mo") as $existingMo) {
                    @unlink($existingMo);
                }
            }

            // Check if component exists
            try {
                if (!$this->weblateClient->componentExists($projectSlug, $componentSlug)) {
                    if (!$silent) {
                        fwrite(STDERR, "  ⚠️  Component not found on Weblate, skipping...\n\n");
                    }
                    continue;
                }
            } catch (Exception $e) {
                if (!$silent) {
                    fwrite(STDERR, "  ❌ Error checking component: " . $e->getMessage() . "\n\n");
                }
                $success = false;
                continue;
            }

            $languagesToDownload = [];

            if ($this->customTargetLanguages) {
                $languagesToDownload = $this->targetLanguages;
            } else {
                try {
                    $languagesToDownload = $this->weblateClient->getComponentLanguages($projectSlug, $componentSlug);
                } catch (Exception $e) {
                    if (!$silent) {
                        fwrite(STDERR, "  ❌ Error fetching language list from Weblate: " . $e->getMessage() . "\n");
                        fwrite(STDERR, "  ⚠️ Falling back to default targetLanguages list\n");
                    }
                    $languagesToDownload = $this->targetLanguages;
                }
            }

            $languagesToDownload = $this->dedupeWeblateLanguageCodes($languagesToDownload);
            $languagesToDownload = $this->selectWeblateLanguagesForDownload($languagesToDownload);

            // Download translations for each language
            foreach ($languagesToDownload as $language) {
                try {
                    if (!$silent) {
                        $this->output->step("Downloading {$language}");
                    }

                    $poContent = $this->weblateClient->downloadPo($projectSlug, $componentSlug, $language);

                    if ($poContent) {
                        $wpLocale = $this->reverseMapWeblateLanguage($language);

                        $poFile = $this->languagesDir . '/' . $textDomain . '-' . $wpLocale . '.po';
                        file_put_contents($poFile, $poContent);
                        chmod($poFile, 0644);

                        // Validate for malformed plural entries and repair if needed
                        $this->validatePluralEntries($poFile);

                        $this->weblateClient->cleanupDuplicatePoHeaders($poFile);
                        $this->weblateClient->removeDuplicateReferences($poFile);
                        $this->weblateClient->removeDuplicateExtractedComments($poFile);

                        $this->revertPluginNameTranslations($poFile);

                        $this->applyTranslationOverrides($poFile, $wpLocale);

                        if (!$silent) {
                            $this->output->step("Saved {$language}");
                        }
                        $totalDownloaded++;
                    } else {
                        if (!$silent) {
                            $this->output->step("{$language} (not available)");
                        }
                    }
                } catch (Exception $e) {
                    if (!$silent) {
                        $this->output->warning("{$language}: " . $e->getMessage());
                    }
                }
            }

            $this->cleanupDuplicateLocaleFiles($textDomain, $silent);

            if (!$silent) {
                $this->output->blankLine();
            }
        }

        if (!$silent && $start !== null) {
            $this->output->separator();
            $this->output->line('Downloaded ' . $totalDownloaded . ' translation file(s).');
            $this->writeCliCompletion($start, $success);
        }

        return $success;
    }

    private function cleanupDuplicateLocaleFiles(string $textDomain, bool $silent = false): void
    {
        $preferBase = getenv('WEBLATE_PREFER_BASE_LANGUAGE') === 'true' || getenv('WEBLATE_PREFER_BASE_LANGUAGE') === '1';

        $allPo = glob($this->languagesDir . "/{$textDomain}-*.po") ?: [];
        $allMo = glob($this->languagesDir . "/{$textDomain}-*.mo") ?: [];

        $byLocale = [];
        foreach ($allPo as $poPath) {
            $baseName = basename($poPath);
            if (!preg_match('/^' . preg_quote($textDomain, '/') . '-(.+)\\.po$/', $baseName, $m)) {
                continue;
            }

            $locale = $m[1];
            $byLocale[$locale]['po'] = $poPath;
        }

        foreach ($allMo as $moPath) {
            $baseName = basename($moPath);
            if (!preg_match('/^' . preg_quote($textDomain, '/') . '-(.+)\\.mo$/', $baseName, $m)) {
                continue;
            }

            $locale = $m[1];
            $byLocale[$locale]['mo'] = $moPath;
        }

        $localesByBase = [];
        foreach (array_keys($byLocale) as $locale) {
            $base = strtolower(explode('_', $locale, 2)[0]);
            $localesByBase[$base][] = $locale;
        }

        $toDeleteLocales = [];

        foreach ($localesByBase as $base => $locales) {
            $hasBase = in_array($base, $locales, true);
            if (!$hasBase) {
                continue;
            }

            $regionals = array_values(array_filter($locales, static function ($l) use ($base) {
                return $l !== $base;
            }));

            if (empty($regionals)) {
                continue;
            }

            if ($preferBase) {
                foreach ($regionals as $regional) {
                    $toDeleteLocales[] = $regional;
                }
            } else {
                $toDeleteLocales[] = $base;
            }
        }

        $toDeleteLocales = array_values(array_unique($toDeleteLocales));
        foreach ($toDeleteLocales as $locale) {
            if (isset($byLocale[$locale]['po'])) {
                @unlink($byLocale[$locale]['po']);
            }
            if (isset($byLocale[$locale]['mo'])) {
                @unlink($byLocale[$locale]['mo']);
            }

            if (!$silent) {
                $this->output->step("Removed duplicate locale file(s): {$locale}");
            }
        }
    }

    /**
     * Mark identical translations as fuzzy in PO file
     *
     * @param string $poFile
     * @param string|null $language Target language code for override lookup
     */
    private function markIdenticalTranslationsAsFuzzy($poFile, $language = null)
    {
        $content = @file_get_contents($poFile);
        if ($content === false) {
            fwrite(STDERR, "Warning: Failed to read file: {$poFile}\n");
            return;
        }
        if ($content === '') {
            fwrite(STDERR, "Warning: Empty file: {$poFile}\n");
            return;
        }

        // Build set of words that are intentionally kept as-is
        $protectedWords = [];

        // 1. Dictionary defaults
        $dictDefaults = $this->loadDictionaryDefaults();
        foreach ($dictDefaults as $source => $target) {
            if (strcasecmp($source, $target) === 0) {
                $protectedWords[strtolower($source)] = true;
            }
        }

        // 2. Plugin name
        $pluginName = $this->getPluginNameForExclusion();
        if ($pluginName) {
            $protectedWords[strtolower($pluginName)] = true;
        }

        // 3. Current env var overrides for this language
        if ($language !== null) {
            $envOverrides = $this->getOverridesForLanguage($language);
            foreach ($envOverrides as $source => $target) {
                if (strcasecmp($source, $target) === 0) {
                    $protectedWords[strtolower($source)] = true;
                }
            }
        }

        $lines  = explode("\n", $content);
        $result = [];

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];

            if (preg_match('/^msgid\s+"(.+)"$/', $line, $msgidMatch)) {
                $msgid = $msgidMatch[1];

                if ($msgid === '') {
                    $result[] = $line;
                    continue;
                }

                // Only handle simple one-line msgstr directly after msgid
                if (
                    $i + 1 < count($lines)
                    && preg_match('/^msgstr\s+"(.+)"$/', $lines[$i + 1], $msgstrMatch)
                ) {
                    $msgstr = $msgstrMatch[1];

                    if ($msgid === $msgstr && $msgid !== '') {
                        if (!isset($protectedWords[strtolower($msgid)])) {
                            $commentIndex = count($result) - 1;
                            while ($commentIndex >= 0 && !preg_match('/^#[,:]/', $result[$commentIndex])) {
                                $commentIndex--;
                            }

                            if ($commentIndex >= 0) {
                                if (!preg_match('/\bfuzzy\b/', $result[$commentIndex])) {
                                    if (preg_match('/^#,\s*(.*)$/', $result[$commentIndex], $matches)) {
                                        $result[$commentIndex] = '#, fuzzy, ' . $matches[1];
                                    } else {
                                        $result[$commentIndex] .= ', fuzzy';
                                    }
                                }
                            } else {
                                $result[] = '#, fuzzy';
                            }
                        }
                    }
                }
            }

            $result[] = $line;
        }

        file_put_contents($poFile, implode("\n", $result));
    }

    /**
     * Execute translation
     *
     * @return bool
     */
    public function translate()
    {
        $start = $this->writeCliBannerAndPluginContext();
        $pluginSlug = $this->getPluginSlug();

        $this->output->phase('Run configuration');
        $this->output->bullet('Project path: ' . $this->pluginRoot);
        $this->output->bullet('Target languages: ' . implode(', ', $this->targetLanguages));
        if (!empty($this->skippedLanguages)) {
            $this->output->bullet('Skipped languages: ' . implode(', ', $this->skippedLanguages) . ' (human translators)');
        }
        $this->output->bullet('Mode: ' . ($this->dryRun ? 'DRY RUN (no API calls)' : 'LIVE TRANSLATION'));
        $this->output->bullet('Weblate: ' . ($this->weblateEnabled ? 'enabled' : 'disabled'));
        $this->output->blankLine();
        $this->output->separator();

        $apiKey = $this->getApiKey();
        if (!$apiKey) {
            fwrite(STDERR, "Warning: OPENAI_API_KEY environment variable not set.\n");
            fwrite(STDERR, "Please set your OpenAI API key:\n");
            fwrite(STDERR, "  export OPENAI_API_KEY=your-api-key-here\n\n");

            if (!$this->dryRun) {
                $this->writeCliCompletion($start, false);

                return false;
            }
        }
        if (!$this->weblateEnabled && !getenv('WEBLATE_API_TOKEN')) {
            fwrite(STDERR, "Warning: WEBLATE_API_TOKEN environment variable not set.\n");
            fwrite(STDERR, "Weblate integration is disabled; translations will not be synced.\n\n");
        }

        // Step 1: Download existing translations from Weblate (if enabled)
        if ($this->weblateEnabled && !$this->dryRun) {
            $this->output->phase('Downloading existing translations from Weblate');
            try {
                $this->downloadFromWeblate(true);
                $this->output->step('Existing translations refreshed from Weblate');
            } catch (Exception $e) {
                fwrite(STDERR, "⚠️  No existing translations found on Weblate (this is normal for new projects)\n\n");
            }
            $this->output->separator();
        }

        $potFiles = $this->findPotFiles();

        if (empty($potFiles)) {
            fwrite(STDERR, "Error: No .pot files found in {$this->languagesDir}\n");
            $this->writeCliCompletion($start, false);

            return false;
        }

        // Step 2: Download translations from translate.wordpress.org
        if (!$this->dryRun) {
            $this->output->phase('Merging translations from translate.wordpress.org');
            $totalWpOrgDownloaded = 0;
            foreach ($potFiles as $potFile) {
                $potFileName = basename($potFile);
                $textDomain = str_replace('.pot', '', $potFileName);
                $totalWpOrgDownloaded += $this->downloadFromWordPressOrg($textDomain);
            }
            if ($totalWpOrgDownloaded > 0) {
                $this->output->step("Merged translations from wordpress.org for {$totalWpOrgDownloaded} language(s)");
            } else {
                $this->output->step('No translations merged from wordpress.org');
            }
        }

        $this->output->phase('Running AI translation with Potomatic');
        $this->output->step('POT files found: ' . count($potFiles));

        $this->createTempDictionaryDir();

        $success = true;
        foreach ($potFiles as $index => $potFile) {
            $potFileName = basename($potFile);
            $textDomain = str_replace('.pot', '', $potFileName);

            $this->output->separator();
            $this->output->step('POT ' . ($index + 1) . '/' . count($potFiles) . ': ' . $potFileName);
            $this->output->step('Text domain: ' . $textDomain);

            $existingPoFiles = glob($this->languagesDir . "/{$textDomain}-*.po");
            foreach ($existingPoFiles as $existingPoFile) {
                $poLang = $this->extractLanguageFromPoFile($existingPoFile, $textDomain);
                if ($poLang) {
                    $this->clearStaleOverrides($existingPoFile, $poLang);
                }
            }

            try {
                $command = $this->buildCommand($potFile, $textDomain);

                $this->output->separator();
                $this->output->phase('Running Potomatic AI translation');
                $this->output->step('This may take several minutes depending on the number of strings');
                $this->output->blankLine();

                $returnCode = 0;

                $this->output->startBoxed();
                $descriptorSpec = [
                    1 => ['pipe', 'w'], // stdout
                    2 => ['pipe', 'w'], // stderr
                ];
                $process = proc_open($command, $descriptorSpec, $pipes);
                if (is_resource($process)) {
                    while ($line = fgets($pipes[1])) {
                        $this->output->boxedLine(rtrim($line, "\r\n"));
                    }
                    while ($line = fgets($pipes[2])) {
                        $this->output->boxedLine(rtrim($line, "\r\n"));
                    }
                    foreach ($pipes as $pipe) {
                        fclose($pipe);
                    }
                    $returnCode = proc_close($process);
                } else {
                    $this->output->boxedLine("Failed to execute command.");
                    $returnCode = 1;
                }
                $this->output->endBoxed();


                if ($returnCode === 0) {
                    $poFiles = glob($this->languagesDir . "/{$textDomain}-*.po");
                    foreach ($poFiles as $poFile) {
                        $poLanguage = $this->extractLanguageFromPoFile($poFile, $textDomain);

                        // Skip processing if --languages flag is set and this language is not in the target list
                        if ($this->customTargetLanguages && $poLanguage && !in_array($poLanguage, $this->targetLanguages)) {
                            continue;
                        }

                        if ($this->weblateClient) {
                            $this->weblateClient->cleanupDuplicatePoHeaders($poFile);
                            $this->weblateClient->removeDuplicateReferences($poFile);
                            $this->weblateClient->removeDuplicateExtractedComments($poFile);
                        }

                        $this->repairPluralPipeDelimitedEntries($poFile);

                        // Validate no malformed entries remain
                        $this->validatePluralEntries($poFile);

                        $this->revertPluginNameTranslations($poFile);

                        if ($poLanguage) {
                            $this->applyTranslationOverrides($poFile, $poLanguage);
                        }

                        $this->markIdenticalTranslationsAsFuzzy($poFile, $poLanguage);
                    }

                    $this->output->blankLine();
                    $this->output->step('Successfully processed ' . $potFileName);
                } else {
                    fwrite(STDERR, "\n❌ Error processing {$potFileName}\n\n");
                    $success = false;
                }
            } catch (Exception $e) {
                fwrite(STDERR, "\n❌ Error: " . $e->getMessage() . "\n\n");
                $success = false;
            }
        }

        // Step 4: Upload updated translations to Weblate (if enabled)
        if ($this->weblateEnabled && !$this->dryRun && $success) {
            $this->output->phase('Uploading updated translations to Weblate');
            foreach ($potFiles as $potFile) {
                $potFileName = basename($potFile);
                $textDomain = str_replace('.pot', '', $potFileName);

                try {
                    $this->uploadToWeblateInternal($potFile, $textDomain);
                } catch (Exception $e) {
                    fwrite(STDERR, "⚠️  Warning: Weblate upload failed for {$textDomain}: " . $e->getMessage() . "\n\n");
                }
            }
        }

        $this->cleanupTempDictionaryDir();

        $this->saveOverridesManifest();

        $this->output->separator();
        $this->output->blankLine();
        $this->output->line('Translation ' . ($success ? 'complete' : 'finished with errors') . " for {$pluginSlug}.");
        $this->writeCliCompletion($start, $success);

        return $success;
    }
}
