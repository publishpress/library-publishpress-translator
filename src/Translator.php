<?php

/**
 * Main Translator Class
 *
 * @package PublishPress\Translations
 */

namespace PublishPress\Translations;

use Exception;

class Translator
{
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
        'pt_BR',
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
        'yo',
        'fi',
        'ja',
        'ko_KR'
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
     * Constructor
     *
     * @param string $pluginRoot Plugin root directory
     * @throws Exception
     */
    public function __construct($pluginRoot)
    {
        $this->pluginRoot = rtrim($pluginRoot, '/\\');
        $this->languagesDir = $this->pluginRoot . '/languages';

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

    /**
     * Enable or disable Weblate integration
     *
     * @param bool $enabled
     */
    public function setWeblateEnabled($enabled)
    {
        $this->weblateEnabled = (bool) $enabled;
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
        echo "\n🔧 Repairing plural entries in existing .po files\n";
        echo str_repeat('=', 50) . "\n\n";
        echo "Path: {$this->languagesDir}\n\n";

        $poFiles = glob($this->languagesDir . '/*.po');

        if (empty($poFiles)) {
            fwrite(STDERR, "No .po files found in {$this->languagesDir}\n");
            return false;
        }

        echo "Found " . count($poFiles) . " .po file(s)\n\n";

        $repaired = 0;
        foreach ($poFiles as $poFile) {
            $before = file_get_contents($poFile);
            $this->repairPluralPipeDelimitedEntries($poFile);
            $after = file_get_contents($poFile);

            if ($before !== $after) {
                $baseName = basename($poFile);
                echo "  ✓ Repaired: {$baseName}\n";

                // Regenerate .mo file
                $moFile = substr($poFile, 0, -3) . '.mo';
                $this->convertPoToMo($poFile, $moFile);

                $repaired++;
            }
        }

        echo "\n" . str_repeat('=', 50) . "\n";

        if ($repaired > 0) {
            echo "✨ Repaired {$repaired} file(s) with malformed plural entries.\n\n";
        } else {
            echo "✨ No malformed plural entries found — all files are clean.\n\n";
        }

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
        ];
        if (isset($scriptMap[$code])) {
            return $scriptMap[$code];
        }
        if (strpos($code, '_') !== false) {
            return $code;
        }

        $lang = strtolower($code);

        if (is_dir($this->languagesDir)) {
            $files = glob($this->languagesDir . "/*-{$lang}_*.po");
            if ($files) {
                if (preg_match('/-(' . preg_quote($lang, '/') . '_[A-Z]{2,})\.po$/', $files[0], $m)) {
                    return $m[1];
                }
            }
        }

        // Languages that WordPress uses without region codes
        $baseLanguages = ['ja', 'fil', 'yo', 'fi'];
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
            'ar' => 'ar_SA',
            'hi' => 'hi_IN',
            'vi' => 'vi_VN',
            'el' => 'el_GR',
            'uk' => 'uk_UA',
            'cs' => 'cs_CZ',
            'da' => 'da_DK',
            'sv' => 'sv_SE',
            'sl' => 'sl_SI',
            'et' => 'et_EE',
            'fa' => 'fa_IR',
            'ur' => 'ur_PK',
            'bn' => 'bn_BD',
            'ms' => 'ms_MY',
            'ca' => 'ca_ES',
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
     * Repair plural entries where msgstr[0] contains pipe-delimited forms.
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
            $line = $lines[$i];

            if (preg_match('/^msgid_plural\s+"(.*)"$/', $line)) {
                $result[] = $line;
                $i++;

                $msgstrEntries = [];
                $msgstrRawLines = [];

                while ($i < $count && preg_match('/^msgstr\[(\d+)\]\s+"(.*)"$/', $lines[$i], $m)) {
                    $idx = (int)$m[1];
                    $value = $m[2];
                    $rawLines = [$lines[$i]];
                    $i++;

                    while ($i < $count && preg_match('/^"(.*)"$/', $lines[$i], $cont)) {
                        $value .= $cont[1];
                        $rawLines[] = $lines[$i];
                        $i++;
                    }

                    $msgstrEntries[$idx] = $value;
                    $msgstrRawLines[$idx] = $rawLines;
                }

                if (
                    isset($msgstrEntries[0])
                    && strpos($msgstrEntries[0], '|') !== false
                    && $this->allPluralFormsEmptyExcept($msgstrEntries, 0)
                ) {
                    $forms = array_map('trim', explode('|', $msgstrEntries[0]));
                    $nplurals = max(count($msgstrEntries), count($forms));

                    for ($formIdx = 0; $formIdx < $nplurals; $formIdx++) {
                        $result[] = 'msgstr[' . $formIdx . '] "' . ($forms[$formIdx] ?? '') . '"';
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
     * Check if all plural forms except the given index are empty
     *
     * @param array $msgstrLines Associative array of index => value
     * @param int   $exceptIndex Index to skip
     * @return bool
     */
    private function allPluralFormsEmptyExcept(array $msgstrLines, int $exceptIndex)
    {
        foreach ($msgstrLines as $idx => $val) {
            if ($idx === $exceptIndex) {
                continue;
            }
            if ($val !== '') {
                return false;
            }
        }
        return true;
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
            $possiblePaths[] = $this->pluginRoot . '/lib/vendor/publishpress/translations/potomatic/potomatic';
            $possiblePaths[] = $this->pluginRoot . '/vendor/publishpress/translations/potomatic/potomatic';
        } else {
            $possiblePaths[] = $this->pluginRoot . '/vendor/publishpress/translations/potomatic/potomatic';
            $possiblePaths[] = $this->pluginRoot . '/lib/vendor/publishpress/translations/potomatic/potomatic';
        }

        // Always check library's own potomatic (for development)
        $possiblePaths[] = __DIR__ . '/../potomatic/potomatic';

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

        echo "\n Uploading to Weblate...\n";
        
        $pluginSlug   = $this->getPluginSlug();
        $projectSlug  = $this->getWeblateProjectSlug();
        $componentSlug = $this->getWeblateComponentSlug($textDomain);
        
        // Step 1: Ensure project exists
        echo "  • Checking project '{$projectSlug}'...\n";
        if (!$this->weblateClient->projectExists($projectSlug)) {
            echo "  • Creating project '{$projectSlug}'...\n";
            $this->weblateClient->createProject($projectSlug, $pluginSlug, $this->getGitRepoUrl());
        }

        // Step 2: Ensure component exists, auto-create if needed
        echo "  • Checking component '{$componentSlug}'...\n";

        if (!$this->weblateClient->componentExists($projectSlug, $componentSlug)) {
            echo "  • Creating component '{$componentSlug}'...\n";
            try {
                $this->weblateClient->createComponent(
                    $projectSlug,
                    $componentSlug,
                    $textDomain,
                    $potFile,
                    $this->getGitRepoUrl()
                );
                echo "  ✓ Component created successfully\n";
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

        echo "  • Uploading POT file (source strings)...\n";
        try {
            $this->weblateClient->uploadPot($projectSlug, $componentSlug, $potFile);
            echo "  ✓ POT file uploaded\n";
        } catch (Exception $e) {
            echo "  ⚠️  Warning: POT upload failed: " . $e->getMessage() . "\n";
            echo "  Continuing with PO file uploads...\n";
        }

        // Step 3: Upload PO files
        echo "  • Uploading translation files...\n";
        
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
                echo "  ⚠️  No PO files found matching specified languages: " . implode(', ', $this->targetLanguages) . "\n";
                return;
            }
            
            echo "  • Uploading " . count($poFilesToUpload) . " language(s): " . implode(', ', $this->targetLanguages) . "\n";
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

            echo "    → Preparing {$languageCode}\n";

            $uploaded = false;
            $maxRetries = 3;
            
            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                try {
                    $this->weblateClient->ensureTranslation($projectSlug, $componentSlug, $languageCode);
                    $this->weblateClient->uploadPo($projectSlug, $componentSlug, $languageCode, $poFile);
                    echo "    ✓ Uploaded {$languageCode}\n";
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
                        echo "    ⊘ {$languageCode} (source language, read-only)\n";
                        $uploaded = true;
                        break;
                    }

                    // Check for duplicate constraint error
                    if (strpos($e->getMessage(), 'duplicate key value violates unique constraint') !== false || 
                        strpos($e->getMessage(), 'trans_unit_translation_id_id_hash') !== false) {
                        echo "      ⚠️  Duplicate entries detected, cleaning PO file...\n";
                        $this->deduplicatePoFile($poFile);
                                        
                        if ($attempt < $maxRetries) {
                            echo "      🔄 Retrying upload after cleanup...\n";
                            sleep(2);
                            continue;
                        }
                    }
                    
                    if (($is503 || $isTlsError) && $attempt < $maxRetries) {
                        $backoffDelay = $attempt * 10;
                        echo "    ⏳ Retrying {$languageCode} after {$backoffDelay}s (attempt {$attempt}/{$maxRetries})...\n";
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
            echo "  ✓ All translations uploaded\n";
        }

        echo "  View at: https://weblate.publishpress.com/projects/{$projectSlug}/{$componentSlug}/\n\n";
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

        echo "\n📤 PublishPress Translation Upload\n";
        echo str_repeat('=', 50) . "\n\n";
        echo "Plugin: {$pluginSlug}\n";
        echo "Path: {$this->pluginRoot}\n\n";

        $potFiles = $this->findPotFiles();

        if (empty($potFiles)) {
            fwrite(STDERR, "Error: No .pot files found in {$this->languagesDir}\n");
            return false;
        }

        echo "📤 Uploading translations to Weblate...\n";
        echo "POT files found: " . count($potFiles) . "\n\n";

        $success = true;
        foreach ($potFiles as $potFile) {
            $potFileName = basename($potFile);
            $textDomain = str_replace('.pot', '', $potFileName);

            echo "[" . basename($potFile) . "]\n";

            try {
                $this->uploadToWeblateInternal($potFile, $textDomain);
            } catch (Exception $e) {
                fwrite(STDERR, "⚠️  Warning: Weblate upload failed for {$textDomain}: " . $e->getMessage() . "\n\n");
                $success = false;
            }
        }

        echo str_repeat('=', 50) . "\n";
        echo "✨ Upload " . ($success ? 'complete' : 'finished with errors') . " for {$pluginSlug}!\n\n";

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

        $pluginSlug = $this->getPluginSlug();
        $projectSlug = $this->getWeblateProjectSlug();

        if (!$silent) {
            echo "\n⬇️  Downloading Translations from Weblate\n";
            echo str_repeat('=', 50) . "\n\n";
            echo "Plugin: {$pluginSlug}\n";
            echo "Project: {$projectSlug}\n\n";
        }

        $potFiles = $this->findPotFiles();

        if (empty($potFiles)) {
            if (!$silent) {
                fwrite(STDERR, "Error: No .pot files found in {$this->languagesDir}\n");
            }
            return false;
        }

        echo "📤 Downloading translations from Weblate...\n";
        echo "POT files found: " . count($potFiles) . "\n\n";

        $success = true;
        $totalDownloaded = 0;

        foreach ($potFiles as $potFile) {
            $potFileName = basename($potFile);
            $textDomain = str_replace('.pot', '', $potFileName);
            $componentSlug = $this->getWeblateComponentSlug($textDomain);
            
            if (!$silent) {
                echo "Component: {$componentSlug}\n";
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
                        echo "    → Downloading {$language}\n";
                    }

                    $poContent = $this->weblateClient->downloadPo($projectSlug, $componentSlug, $language);
                    
                    if ($poContent) {
                        $wpLocale = $this->reverseMapWeblateLanguage($language);
                        
                        $poFile = $this->languagesDir . '/' . $textDomain . '-' . $wpLocale . '.po';
                        file_put_contents($poFile, $poContent);
                        chmod($poFile, 0644);

                        $this->weblateClient->cleanupDuplicatePoHeaders($poFile);
                        
                        $this->revertPluginNameTranslations($poFile);
                        
                        $moFile = $this->languagesDir . '/' . $textDomain . '-' . $wpLocale . '.mo';
                        $this->convertPoToMo($poFile, $moFile);

                        if (!$silent) {
                            echo "  ✓ {$language}\n";
                        }
                        $totalDownloaded++;
                    } else {
                        if (!$silent) {
                            echo "  ⊘ {$language} (not available)\n";
                        }
                    }
                } catch (Exception $e) {
                    if (!$silent) {
                        echo "  ✗ {$language}: " . $e->getMessage() . "\n";
                    }
                }
            }

            $this->cleanupDuplicateLocaleFiles($textDomain, $silent);

            if (!$silent) {
                echo "\n";
            }
        }

        if (!$silent) {
            echo str_repeat('=', 50) . "\n";
            echo "✨ Downloaded {$totalDownloaded} translation files!\n\n";
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
                echo "    ⊘ Removed duplicate {$locale}\n";
            }
        }
    }

    /**
     * Convert PO file to MO file
     *
     * @param string $poFile Path to PO file
     * @param string $moFile Path to output MO file
     * @return bool True on success
     */
    private function convertPoToMo($poFile, $moFile)
    {
        $entries = [];
        $currentEntry = null;
        $lines = file($poFile, FILE_IGNORE_NEW_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line) || $line[0] === '#') {
                continue;
            }

            if (strpos($line, 'msgid') === 0) {
                if ($currentEntry && !empty($currentEntry['msgid']) && !empty($currentEntry['msgstr'])) {
                    $entries[] = $currentEntry;
                }
                $currentEntry = ['msgid' => $this->extractString($line), 'msgstr' => ''];
            } elseif (strpos($line, 'msgstr') === 0 && $currentEntry) {
                $currentEntry['msgstr'] = $this->extractString($line);
            }
        }

        if ($currentEntry && !empty($currentEntry['msgid']) && !empty($currentEntry['msgstr'])) {
            $entries[] = $currentEntry;
        }

        $mo = $this->buildMoFile($entries);
        $written = file_put_contents($moFile, $mo) !== false;
        if ($written) {
            chmod($moFile, 0644);
        }

        return $written;
    }

    /**
     * Extract string from PO line
     *
     * @param string $line
     * @return string
     */
    private function extractString($line)
    {
        if (preg_match('/"(.*)"/', $line, $matches)) {
            return stripcslashes($matches[1]);
        }
        return '';
    }

    /**
     * Build MO file content
     *
     * @param array $entries
     * @return string
     */
    private function buildMoFile($entries)
    {
        $magic = 0x950412de;
        $revision = 0;
        $count = count($entries);

        $idsOffset = 28;
        $strsOffset = $idsOffset + 8 * $count;

        $ids = '';
        $strs = '';
        $idsIndex = [];
        $strsIndex = [];

        foreach ($entries as $entry) {
            $idsIndex[] = [strlen($ids), strlen($entry['msgid'])];
            $ids .= $entry['msgid'] . "\0";

            $strsIndex[] = [strlen($strs), strlen($entry['msgstr'])];
            $strs .= $entry['msgstr'] . "\0";
        }

        $keysOffset = $strsOffset + 8 * $count;
        $valsOffset = $keysOffset + strlen($ids);

        $mo = pack('Iiiiiii', $magic, $revision, $count, $idsOffset, $strsOffset, 0, 0);

        foreach ($idsIndex as $index) {
            $mo .= pack('ii', $index[1], $keysOffset + $index[0]);
        }

        foreach ($strsIndex as $index) {
            $mo .= pack('ii', $index[1], $valsOffset + $index[0]);
        }

        $mo .= $ids . $strs;

        return $mo;
    }

    /**
     * Mark identical translations as fuzzy in PO file
     *
     * @param string $poFile
     */
    private function markIdenticalTranslationsAsFuzzy($poFile)
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
        $pluginSlug = $this->getPluginSlug();

        echo "\n🌍 PublishPress Translation Tool\n";
        echo str_repeat('=', 50) . "\n\n";
        echo "Plugin: {$pluginSlug}\n";
        echo "Path: {$this->pluginRoot}\n";
        echo "Languages: " . implode(', ', $this->targetLanguages) . "\n";
        echo "Mode: " . ($this->dryRun ? 'DRY RUN (no API calls)' : 'LIVE TRANSLATION') . "\n";
        echo "Weblate: " . ($this->weblateEnabled ? 'Enabled' : 'Disabled') . "\n\n";

        $apiKey = $this->getApiKey();
        if (!$apiKey) {
            fwrite(STDERR, "Warning: OPENAI_API_KEY environment variable not set.\n");
            fwrite(STDERR, "Please set your OpenAI API key:\n");
            fwrite(STDERR, "  export OPENAI_API_KEY=your-api-key-here\n\n");

            if (!$this->dryRun) {
                return false;
            }
        }
        if (!$this->weblateEnabled && !getenv('WEBLATE_API_TOKEN')) {
            fwrite(STDERR, "Warning: WEBLATE_API_TOKEN environment variable not set.\n");
            fwrite(STDERR, "Weblate integration is disabled; translations will not be synced.\n\n");
        }

        // Step 1: Download existing translations from Weblate (if enabled)
        if ($this->weblateEnabled && !$this->dryRun) {
            echo "📥 Step 1: Downloading existing translations from Weblate...\n";
            try {
                $this->downloadFromWeblate(true); // Silent mode
                echo "✓ Existing translations downloaded\n\n";
            } catch (Exception $e) {
                fwrite(STDERR, "⚠️  No existing translations found on Weblate (this is normal for new projects)\n\n");
            }
        }

        $potFiles = $this->findPotFiles();

        if (empty($potFiles)) {
            fwrite(STDERR, "Error: No .pot files found in {$this->languagesDir}\n");
            return false;
        }

        echo "📝 Step 2: Running AI translation with Potomatic...\n";
        echo "POT files found: " . count($potFiles) . "\n\n";

        $success = true;
        foreach ($potFiles as $index => $potFile) {
            $potFileName = basename($potFile);
            $textDomain = str_replace('.pot', '', $potFileName);

            echo "[" . ($index + 1) . "/" . count($potFiles) . "] Processing: {$potFileName}\n";
            echo "Text domain: {$textDomain}\n";

            try {
                $command = $this->buildCommand($potFile, $textDomain);

                echo "\n" . str_repeat('-', 50) . "\n";
                echo "🤖 Running Potomatic AI Translation...\n";
                echo "This may take several minutes depending on the number of strings.\n";
                echo str_repeat('-', 50) . "\n\n";

                $returnCode = 0;
                passthru($command . ' 2>&1', $returnCode);

                if ($returnCode === 0) {

                    $poFiles = glob($this->languagesDir . "/{$textDomain}-*.po");
                    foreach ($poFiles as $poFile) {
                        if ($this->weblateClient) {
                            $this->weblateClient->cleanupDuplicatePoHeaders($poFile);
                        }

                        $this->repairPluralPipeDelimitedEntries($poFile);
                        
                        $this->revertPluginNameTranslations($poFile);
                        
                        $this->markIdenticalTranslationsAsFuzzy($poFile);

                        $baseName = basename($poFile, '.po');
                        $moFile = $this->languagesDir . '/' . $baseName . '.mo';
                        $this->convertPoToMo($poFile, $moFile);
                    }

                    echo "\n✅ Successfully processed {$potFileName}\n\n";
                } else {
                    fwrite(STDERR, "\n❌ Error processing {$potFileName}\n\n");
                    $success = false;
                }
                
            } catch (Exception $e) {
                fwrite(STDERR, "\n❌ Error: " . $e->getMessage() . "\n\n");
                $success = false;
            }
        }

        // Step 3: Upload updated translations to Weblate (if enabled)
        if ($this->weblateEnabled && !$this->dryRun && $success) {
            echo "\n📤 Step 3: Uploading updated translations to Weblate...\n\n";
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

        echo str_repeat('=', 50) . "\n";
        echo "✨ Translation " . ($success ? 'complete' : 'finished with errors') . " for {$pluginSlug}!\n\n";

        return $success;
    }
}