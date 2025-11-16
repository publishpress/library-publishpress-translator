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
     * Target languages
     * 
     * @var array
     */
    private $targetLanguages = [
        'de_DE',
        'pt_BR', 
        'id_ID',
        'fil',
        'ru_RU',
        'yo',
        'fi',
        'ja',
        'ko_KR'
    ];
    
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
     * Potomatic settings
     * 
     * @var array
     */
    private $potomaticSettings = [
        'model' => 'gpt-4o-mini',
        'batch_size' => 20,
        'jobs' => 2,
        'max_cost' => 5.0,
        'verbose_level' => 2,
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
        $this->targetLanguages = $languages;
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
            strpos($this->pluginRoot, '/project') === 0,
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
        
        $pluginSlug = $this->getPluginSlug();
        $projectSlug = $this->getWeblateProjectSlug();
        $componentSlug = $textDomain;
        
        // Step 1: Ensure project exists
        echo "  • Checking project '{$projectSlug}'...\n";
        if (!$this->weblateClient->projectExists($projectSlug)) {
            echo "  • Creating project '{$projectSlug}'...\n";
            $this->weblateClient->createProject($projectSlug, $pluginSlug);
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
                throw new Exception("Failed to create component: " . $e->getMessage());
            }
        }

        // Step 3: Upload all PO files from local languages directory
        echo "  • Uploading translation files...\n";
        $poFiles = glob($this->languagesDir . "/{$componentSlug}-*.po");
        
        $uploadedCount = 0;
        $failedCount = 0;
        
        foreach ($poFiles as $poFile) {
            preg_match("/{$componentSlug}-(.+)\.po$/", basename($poFile), $matches);
            if (!isset($matches[1])) {
                continue;
            }
            
            $languageCode = $matches[1];

            echo "    → Preparing {$languageCode}\n";

            try {
                $this->weblateClient->uploadPo($projectSlug, $componentSlug, $languageCode, $poFile);
                echo "    ✓ Uploaded {$languageCode}\n";
                $uploadedCount++;
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'read-only') !== false && 
                    in_array($languageCode, ['en', 'en_US', 'en_GB'])) {
                    echo "    ⊘ {$languageCode} (source language, read-only)\n";
                } else {
                    echo "    ⚠️  Failed to upload {$languageCode}: " . $e->getMessage() . "\n";
                    $failedCount++;
                }
            }
        }
        
        if ($failedCount > 0) {
            echo "  ⚠️  {$uploadedCount} uploaded, {$failedCount} failed\n";
        } else {
            echo "  ✓ All translations uploaded\n";
        }

        echo "  View at: https://hosted.weblate.org/projects/{$projectSlug}/{$componentSlug}/\n\n";
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
        
        $success = true;
        $totalDownloaded = 0;
        
        foreach ($potFiles as $potFile) {
            $potFileName = basename($potFile);
            $textDomain = str_replace('.pot', '', $potFileName);
            
            if (!$silent) {
                echo "Component: {$textDomain}\n";
            }
            
            // Check if component exists
            try {
                if (!$this->weblateClient->componentExists($projectSlug, $textDomain)) {
                    if (!$silent) {
                        echo "  ⚠️  Component not found on Weblate, skipping...\n\n";
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
            
            // Download translations for each target language
            foreach ($this->targetLanguages as $language) {
                try {
                    $poContent = $this->weblateClient->downloadPo($projectSlug, $textDomain, $language);
                    
                    if ($poContent) {
                        $poFile = $this->languagesDir . '/' . $textDomain . '-' . $language . '.po';
                        file_put_contents($poFile, $poContent);
                        
                        $moFile = $this->languagesDir . '/' . $textDomain . '-' . $language . '.mo';
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
        return file_put_contents($moFile, $mo) !== false;
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
        $content = file_get_contents($poFile);
        if ($content === false || $content === '') {
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
        
        if (!$this->dryRun && !$this->getApiKey()) {
            fwrite(STDERR, "Error: OPENAI_API_KEY environment variable not set.\n");
            fwrite(STDERR, "Please set your OpenAI API key:\n");
            fwrite(STDERR, "  export OPENAI_API_KEY=your-api-key-here\n\n");
            return false;
        }
        
        // Step 1: Download existing translations from Weblate (if enabled)
        if ($this->weblateEnabled && !$this->dryRun) {
            echo "📥 Step 1: Downloading existing translations from Weblate...\n";
            try {
                $this->downloadFromWeblate(true); // Silent mode
                echo "✓ Existing translations downloaded\n\n";
            } catch (Exception $e) {
                echo "⚠️  No existing translations found on Weblate (this is normal for new projects)\n\n";
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
                        $this->markIdenticalTranslationsAsFuzzy($poFile);
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