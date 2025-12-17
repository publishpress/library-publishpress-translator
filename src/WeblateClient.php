<?php

/**
 * Weblate API Client
 *
 * @package PublishPress\Translations
 */

namespace PublishPress\Translations;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Exception;

class WeblateClient
{
    /**
     * Weblate API base URL
     *
     * @var string
     */
    private $apiUrl;

    /**
     * Weblate API token
     *
     * @var string
     */
    private $apiToken;

    /**
     * HTTP client
     *
     * @var Client
     */
    private $client;

    /**
     * Constructor
     *
     * @param string|null $apiUrl
     * @param string|null $apiToken
     * @throws Exception
     */
    public function __construct($apiUrl = null, $apiToken = null)
    {
        $this->apiUrl = $apiUrl ?: getenv('WEBLATE_API_URL') ?: 'https://weblate.publishpress.com/api/';
        $this->apiToken = $apiToken ?: getenv('WEBLATE_API_TOKEN');

        if (!$this->apiToken) {
            throw new Exception(
                "Weblate API token not found.\n" .
                    "Please set WEBLATE_API_TOKEN environment variable.\n" .
                    "Get your token from: https://weblate.publishpress.com/accounts/profile/#api"
            );
        }

        $this->apiUrl = rtrim($this->apiUrl, '/') . '/';
        $timeout = getenv('WEBLATE_API_TIMEOUT') ?: 120;

        $this->client = new Client([
            'base_uri' => $this->apiUrl,
            'headers' => [
                'Authorization' => 'Token ' . $this->apiToken,
                'Accept' => 'application/json',
            ],
            'timeout' => (int) $timeout,
        ]);
    }

    /**
     * Check if project exists
     *
     * @param string $projectSlug
     * @return bool
     */
    public function projectExists($projectSlug)
    {
        try {
            $response = $this->client->get("projects/{$projectSlug}/");
            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            if ($e->getCode() === 404) {
                return false;
            }
            throw new Exception("Error checking project: " . $e->getMessage());
        }
    }

    /**
     * Create a new project
     *
     * @param string $projectSlug
     * @param string $projectName
     * @return array
     * @throws Exception
     */
    public function createProject($projectSlug, $projectName, $gitRepoUrl = null)
    {
        try {
            if ($gitRepoUrl && preg_match('#^https?://github\.com/(.+?)(?:\.git)?/?$#', $gitRepoUrl, $matches)) {
                $webUrl = "https://github.com/{$matches[1]}";
            } else {
                $webUrl = "https://github.com/{$projectSlug}";
            }

            $response = $this->client->post('projects/', [
                'json' => [
                    'name' => $projectName,
                    'slug' => $projectSlug,
                    'web' => $webUrl,
                ]
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            $errorBody = '';
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $errorBody = $e->getResponse()->getBody()->getContents();
            }

            if (strpos($errorBody, '"code":"unique"') !== false &&
                strpos($errorBody, '"attr":"slug"') !== false) {
                return [
                    'slug' => $projectSlug,
                    'name' => $projectName,
                ];
            }

            throw new Exception("Error creating project: " . $e->getMessage() . "\n" . $errorBody);
        }
    }

    /**
     * Check if component exists
     *
     * @param string $projectSlug
     * @param string $componentSlug
     * @return bool
     */
    public function componentExists($projectSlug, $componentSlug)
    {
        try {
            $response = $this->client->get("components/{$projectSlug}/{$componentSlug}/");
            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            if ($e->getCode() === 404) {
                return false;
            }
            throw new Exception("Error checking component: " . $e->getMessage());
        }
    }

    /**
     * Create a new component
     *
     * @param string $projectSlug
     * @param string $componentSlug
     * @param string $componentName
     * @param string $potFilePath
     * @param string|null $gitRepoSlug GitHub repo slug
     * @return array
     * @throws Exception
     */
    public function createComponent($projectSlug, $componentSlug, $componentName, $potFilePath, $gitRepoSlug = null)
    {
        $skipVcs = getenv('WEBLATE_SKIP_VCS') === 'true' || getenv('WEBLATE_SKIP_VCS') === '1';
        
        try {
            $potContent = file_get_contents($potFilePath);
            if ($potContent === false) {
                throw new Exception("Failed to read POT file: {$potFilePath}");
            }

            $componentData = [
                'name' => $componentName,
                'slug' => $componentSlug,
                'file_format' => 'po',
                'manage_units' => false,
            ];

            $branch = getenv('WEBLATE_GIT_BRANCH') ?: 'development';
            
            if ($skipVcs) {
                $zipPath = sys_get_temp_dir() . '/' . $componentSlug . '-' . time() . '.zip';
                $zip = new \ZipArchive();
                
                if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
                    throw new Exception("Failed to create ZIP file for component creation");
                }

                $zip->addFile($potFilePath, basename($potFilePath));
                
                // Create a dummy en_US.po file so Weblate can detect the language pattern
                $potContent = file_get_contents($potFilePath);
                $dummyPoPath = sys_get_temp_dir() . '/' . $componentSlug . '-en_US.po';
                file_put_contents($dummyPoPath, $potContent);
                $zip->addFile($dummyPoPath, $componentSlug . '-en_US.po');
                
                $zip->close();
                
                // Clean up temp PO file
                @unlink($dummyPoPath);
                
                $componentData['repo'] = 'local:';
                $componentData['vcs'] = 'local';
                $componentData['branch'] = $branch;
                $componentData['file_format'] = 'po';
                $componentData['filemask'] = $componentSlug . '-*.po';
                $componentData['new_lang'] = 'add';
                $componentData['new_base'] = basename($potFilePath);
                
                // Use multipart form data with zipfile
                $response = $this->client->post("projects/{$projectSlug}/components/", [
                    'multipart' => [
                        ['name' => 'name', 'contents' => $componentName],
                        ['name' => 'slug', 'contents' => $componentSlug],
                        ['name' => 'file_format', 'contents' => 'po'],
                        ['name' => 'filemask', 'contents' => $componentSlug . '-*.po'],
                        ['name' => 'repo', 'contents' => 'local:'],
                        ['name' => 'vcs', 'contents' => 'local'],
                        ['name' => 'branch', 'contents' => $branch],
                        ['name' => 'new_lang', 'contents' => 'add'],
                        ['name' => 'new_base', 'contents' => basename($potFilePath)],
                        ['name' => 'manage_units', 'contents' => 'false'],
                        [
                            'name' => 'zipfile',
                            'contents' => fopen($zipPath, 'r'),
                            'filename' => basename($zipPath)
                        ],
                    ]
                ]);
                
                // Clean up temp ZIP file
                @unlink($zipPath);
                
                $result = json_decode($response->getBody()->getContents(), true);
                return $result;
            } else {
                $componentData['filemask'] = "languages/{$componentSlug}-*.po";
                $componentData['new_base'] = "languages/{$componentSlug}.pot";
                $componentData['new_lang'] = 'add';
                $repoType = getenv('WEBLATE_REPO_TYPE') ?: 'https';
                $overrideRepoUrl = getenv('WEBLATE_REPO_URL') ?: null;
                $overridePushUrl = getenv('WEBLATE_PUSH_URL') ?: null;

                if ($overrideRepoUrl) {
                    $repoUrl = $overrideRepoUrl;

                    if ($overridePushUrl !== null) {
                        $pushUrl = $overridePushUrl;
                    } else {
                        if (strpos($overrideRepoUrl, 'git@') === 0 || strpos($overrideRepoUrl, '@github.com:') !== false) {
                            $pushUrl = $overrideRepoUrl;
                        } else {
                            $pushUrl = '';
                        }
                    }

                } else {

                    if ($gitRepoSlug && preg_match('#^https?://#', $gitRepoSlug)) {

                        if (
                            $repoType === 'ssh'
                            && preg_match('#^https?://github\.com/([^/]+)/([^/]+?)(?:\.git)?/?$#', $gitRepoSlug, $matches)
                        ) {
                            $owner = $matches[1];
                            $name  = $matches[2];
                            $repoUrl = "git@github.com:{$owner}/{$name}.git";
                            $pushUrl = $repoUrl;
                        } else {
                            $repoUrl = $gitRepoSlug;
                            $pushUrl = ($repoType === 'ssh') ? $gitRepoSlug : '';
                        }

                    } else {
                        $repoSlug = $gitRepoSlug ?: $componentSlug;

                        if ($repoType === 'ssh') {
                            $repoUrl = "git@github.com:publishpress/{$repoSlug}.git";
                            $pushUrl = "git@github.com:publishpress/{$repoSlug}.git";
                        } else {
                            $repoUrl = "https://github.com/publishpress/{$repoSlug}.git";
                            $pushUrl = '';
                        }
                    }
                }
                
                $componentData['repo'] = $repoUrl;
                $componentData['branch'] = $branch;
                $componentData['push'] = $pushUrl;
                $componentData['vcs'] = 'git';
                $componentData['update_on_commit'] = false;
            }

            $response = $this->client->post("projects/{$projectSlug}/components/", [
                'json' => $componentData
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            return $result;
        } catch (GuzzleException $e) {
            $errorBody = '';
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $errorBody = $e->getResponse()->getBody()->getContents();
            }

            if (strpos($errorBody, 'requires authentication') !== false || 
                strpos($errorBody, '"attr":"repo"') !== false) {
                
                if ($skipVcs) {
                    throw new Exception("Error creating component with local VCS: " . $e->getMessage() . "\n" . $errorBody);
                }
                
                $helpMessage = "Error creating component: Repository requires authentication.\n\n";
                $helpMessage .= "Options to fix this:\n";
                $helpMessage .= "  1. Set WEBLATE_SKIP_VCS=true to create components without VCS integration\n";
                $helpMessage .= "  2. Configure Git credentials:\n";
                $helpMessage .= "     - Set WEBLATE_REPO_URL to a credentialed HTTPS URL (e.g., https://username:token@github.com/owner/repo.git)\n";
                $helpMessage .= "     - Or set WEBLATE_REPO_TYPE=ssh and configure SSH keys in Weblate\n";
                $helpMessage .= "     - Or set WEBLATE_REPO_URL to an SSH URL (e.g., git@github.com:owner/repo.git)\n\n";
                $helpMessage .= "Original error: " . $e->getMessage() . "\n" . $errorBody;
                throw new Exception($helpMessage);
            }

            throw new Exception("Error creating component: " . $e->getMessage() . "\n" . $errorBody);
        }
    }
    
    /**
     * Clean POT file for Weblate by removing fuzzy flag from header
     *
     * @param string $potFilePath
     * @return string Cleaned POT content
     */
    private function cleanPotFileForWeblate($potFilePath)
    {
        $content = file_get_contents($potFilePath);
        if ($content === false) {
            throw new Exception("Failed to read POT file: {$potFilePath}");
        }
        
        $lines = explode("\n", $content);
        $cleanedLines = [];
        $headerFuzzyRemoved = false;
        
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $trimmed = trim($line);

            if (!$headerFuzzyRemoved && preg_match('/^#,\s*fuzzy/', $trimmed)) {
                $lookAhead = $i + 1;
                while ($lookAhead < count($lines)) {
                    $nextLine = trim($lines[$lookAhead]);
                    if (empty($nextLine) || preg_match('/^#/', $nextLine)) {
                        $lookAhead++;
                        continue;
                    }
                    if (preg_match('/^msgid\s+""?\s*$/', $nextLine)) {
                        $headerFuzzyRemoved = true;
                        continue 2;
                    }
                    break;
                }
            }
            
            $cleanedLines[] = $line;
        }
        
        return implode("\n", $cleanedLines);
    }

    /**
     * Upload POT file to component
     *
     * @param string $projectSlug
     * @param string $componentSlug
     * @param string $potFilePath
     * @return array
     * @throws Exception
     */
    public function uploadPot($projectSlug, $componentSlug, $potFilePath)
    {
        try {
            $cleanedContent = $this->cleanPotFileForWeblate($potFilePath);
            $hasVcs = $this->componentHasVcs($projectSlug, $componentSlug);
            
            if ($hasVcs) {
                $response = $this->client->post(
                    "translations/{$projectSlug}/{$componentSlug}/en/file/",
                    [
                        'multipart' => [
                            [
                                'name' => 'file',
                                'contents' => fopen($potFilePath, 'r'),
                                'filename' => basename($potFilePath),
                            ],
                            [
                                'name' => 'method',
                                'contents' => 'replace',
                            ],
                        ]
                    ]
                );
            } else {
                $this->ensureTranslation($projectSlug, $componentSlug, 'en');
                
                $response = $this->client->post(
                    "translations/{$projectSlug}/{$componentSlug}/en/file/",
                    [
                        'multipart' => [
                            [
                                'name' => 'file',
                                'contents' => $cleanedContent,
                                'filename' => basename($potFilePath),
                            ],
                            [
                                'name' => 'method',
                                'contents' => 'source',
                            ],
                        ]
                    ]
                );
            }
            
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new Exception("Error uploading POT file: " . $e->getMessage());
        }
    }

    private function componentHasVcs($projectSlug, $componentSlug)
    {
        try {
            $response = $this->client->get("components/{$projectSlug}/{$componentSlug}/");
            $component = json_decode($response->getBody()->getContents(), true);
            
            if (empty($component['repo'])) {
                return false;
            }

            if (strpos($component['repo'], 'weblate://') === 0) {
                return false;
            }

            if ($component['repo'] === 'local:') {
                return false;
            }

            return true;
        } catch (GuzzleException $e) {
            return false;
        }
    }

    /**
     * Map WordPress language codes to Weblate language codes
     *
     * @param string $wpLangCode WordPress language code
     * @return string Weblate language code
     */
    private function mapLanguageCode($wpLangCode)
    {
        // Normalize weird prefixes like "--languages=" coming from filenames
        if (strpos($wpLangCode, '--languages=') === 0) {
            $wpLangCode = substr($wpLangCode, strlen('--languages='));
        }

        // Special mappings that don't follow the standard pattern
        $specialMappings = [
            'zh_CN' => 'zh_Hans',
            'zh_TW' => 'zh_Hant',
            'fil' => 'fil',
            'yo' => 'yo',

            'en_GB' => 'en_GB',
            'en_AU' => 'en_AU',
            'en_CA' => 'en_CA',
            'en_ZA' => 'en_ZA',

            'pt_BR' => 'pt_BR',
            'sr_RS' => 'sr_RS',
            'nb_NO' => 'nb_NO',
            'nb'    => 'nb_NO',
            'pt_PT' => 'pt_PT',

            'nl_BE' => 'nl_BE',
            'nl_NL' => 'nl',

            'de_DE' => 'de',
            'de_DE_formal'  => 'de',
            'de_CH'         => 'de_CH',

            'es_AR'         => 'es_AR',
            'es_CL'         => 'es_CL',
            'es_CO'         => 'es_CO',
            'es_MX'         => 'es_MX',
            'es_ES'         => 'es',

            'bg_BG' => 'bg',
            'cs_CZ' => 'cs',
            'da_DK' => 'da',
            'fr_FR' => 'fr',
            'hu_HU' => 'hu',
            'id_ID' => 'id',
            'it_IT' => 'it',
            'ko_KR' => 'ko',
            'lt_LT' => 'lt',
            'pl_PL' => 'pl',
            'ro_RO' => 'ro',
            'ru_RU' => 'ru',
            'sk_SK' => 'sk',
            'sl_SI' => 'sl',
            'sv_SE' => 'sv',
            'tr_TR' => 'tr',
        ];

        // If there's a special mapping, use it
        if (isset($specialMappings[$wpLangCode])) {
            return $specialMappings[$wpLangCode];
        }

        if (strpos($wpLangCode, '_') !== false) {
            $parts = explode('_', $wpLangCode);
            $languageCode = strtolower($parts[0]);
            $countryCode = strtolower($parts[1]);

            $fullCode = "{$languageCode}_{$countryCode}";

            $fullFormatCodes = ['en_GB', 'pt_BR', 'he_IL', 'sr_RS'];

            if (in_array($fullCode, $fullFormatCodes)) {
                return $fullCode;
            }

            return $languageCode;
        }

        return strtolower($wpLangCode);
    }

    /**
     * Remove duplicate PO file headers while preserving translations
     *
     * @param string $poFilePath Path to PO file to clean
     * @return bool True if file was modified, false otherwise
     */
    public function cleanupDuplicatePoHeaders($poFilePath)
    {
        $content = @file_get_contents($poFilePath);
        if ($content === false || $content === '') {
            return false;
        }

        $lines = explode("\n", $content);
        $cleanedLines = [];
        $headerFound = false;
        $inHeader = false;
        $inHeaderMsgstr = false;
        $currentHeaderLines = [];
        $headerStartLine = -1;
        
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $trimmed = trim($line);
            
            if (preg_match('/^msgid\s+""?\s*$/', $trimmed)) {
                $nextIdx = $i + 1;
                while ($nextIdx < count($lines) && trim($lines[$nextIdx]) === '') {
                    $nextIdx++;
                }
                
                if ($nextIdx < count($lines) && preg_match('/^msgstr\s+""?\s*$/', trim($lines[$nextIdx]))) {
                    if (!$headerFound) {
                        $headerFound = true;
                        $inHeader = true;
                        $headerStartLine = $i;
                        $currentHeaderLines = [$line];
                    } else {
                        $inHeader = true;
                        $inHeaderMsgstr = false;
                        
                        while ($i < count($lines)) {
                            $i++;
                            if ($i >= count($lines)) break;
                            
                            $nextLine = trim($lines[$i]);
                            
                            if (preg_match('/^msgid\s+"(.+)"/', $nextLine)) {
                                $i--;
                                break;
                            }
                            
                            if (preg_match('/^msgid\s+""?\s*$/', $nextLine)) {
                                $i--;
                                break;
                            }
                        }
                    }
                    continue;
                }
            }
            
            if ($inHeader && $headerFound && $headerStartLine >= 0) {
                $currentHeaderLines[] = $line;
                
                if (preg_match('/^msgstr\s+""?\s*$/', $trimmed)) {
                    $inHeaderMsgstr = true;
                } elseif ($inHeaderMsgstr && !preg_match('/^".*"/', $trimmed) && $trimmed !== '') {
                    $inHeader = false;
                    $inHeaderMsgstr = false;
                    
                    foreach ($currentHeaderLines as $headerLine) {
                        $cleanedLines[] = $headerLine;
                    }
                    $currentHeaderLines = [];
                    
                    $cleanedLines[] = $line;
                }
            } elseif (!$inHeader) {
                $cleanedLines[] = $line;
            }
        }
        
        if (!empty($currentHeaderLines)) {
            foreach ($currentHeaderLines as $headerLine) {
                $cleanedLines[] = $headerLine;
            }
        }
        
        $cleanedContent = implode("\n", $cleanedLines);
        
        if ($cleanedContent !== $content) {
            @file_put_contents($poFilePath, $cleanedContent);
            return true;
        }
        
        return false;
    }

    /**
     * Remove duplicate reference (#:) lines from PO file
     *
     * @param string $poFilePath
     * @return void
     */
    private function removeDuplicateReferences($poFilePath)
    {
        $content = @file_get_contents($poFilePath);
        if ($content === false || $content === '') {
            return;
        }
        
        $lines = explode("\n", $content);
        $cleanedLines = [];
        $seenReferences = [];
        $inEntry = false;
        
        foreach ($lines as $line) {
            $trimmed = trim($line);
            
            if (preg_match('/^#:\s*(.+)$/', $trimmed, $matches)) {
                $reference = $matches[1];
                
                if (isset($seenReferences[$reference])) {
                    continue;
                }
                
                $seenReferences[$reference] = true;
                $cleanedLines[] = $line;
            } else {
                if (preg_match('/^msgid\s/', $trimmed)) {
                    $seenReferences = [];
                }
                
                $cleanedLines[] = $line;
            }
        }
        
        $cleanedContent = implode("\n", $cleanedLines);
        if ($cleanedContent !== $content) {
            file_put_contents($poFilePath, $cleanedContent);
        }
    }

    /**
     * Remove fuzzy flag from PO file header
     *
     * @param string $poFilePath
     * @return void
     */
    private function removeFuzzyFromPoHeader($poFilePath)
    {
        $content = @file_get_contents($poFilePath);
        if ($content === false || $content === '') {
            return;
        }
        
        $lines = explode("\n", $content);
        $cleanedLines = [];
        $headerFuzzyRemoved = false;
        
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $trimmed = trim($line);
            
            if (!$headerFuzzyRemoved && preg_match('/^#,\s*fuzzy/', $trimmed)) {
                $lookAhead = $i + 1;
                while ($lookAhead < count($lines)) {
                    $nextLine = trim($lines[$lookAhead]);
                    if (empty($nextLine) || preg_match('/^#/', $nextLine)) {
                        $lookAhead++;
                        continue;
                    }
                    if (preg_match('/^msgid\s+""?\s*$/', $nextLine)) {
                        $headerFuzzyRemoved = true;
                        continue 2;
                    }
                    break;
                }
            }
            
            $cleanedLines[] = $line;
        }
        
        $cleanedContent = implode("\n", $cleanedLines);
        if ($cleanedContent !== $content) {
            file_put_contents($poFilePath, $cleanedContent);
        }
    }
    
    /**
     * Upload PO file for a language
     *
     * @param string $projectSlug
     * @param string $componentSlug
     * @param string $language WordPress language code
     * @param string $poFilePath
     * @return bool
     * @throws Exception
     */
    public function uploadPo($projectSlug, $componentSlug, $language, $poFilePath)
    {
        $maxRetries = 2;
        $attempt    = 0;

        while (true) {
            try {
                $weblateLanguage = $this->mapLanguageCode($language);

                $this->cleanupDuplicatePoHeaders($poFilePath);
                $this->removeDuplicateReferences($poFilePath);
                $this->removeFuzzyFromPoHeader($poFilePath);
                $this->normalizePluralFormsForWeblate($language, $poFilePath);

                $response = $this->client->post(
                    "translations/{$projectSlug}/{$componentSlug}/{$weblateLanguage}/file/",
                    [
                        'multipart' => [
                            [
                                'name'     => 'file',
                                'contents' => fopen($poFilePath, 'r'),
                            ],
                            [
                                'name'     => 'method',
                                'contents' => 'translate',
                            ],
                        ],
                    ]
                );

                return $response->getStatusCode() === 200;

            } catch (GuzzleException $e) {
                $attempt++;

                $is404 = false;
                if (method_exists($e, 'getResponse') && $e->getResponse()) {
                    $is404 = $e->getResponse()->getStatusCode() === 404;
                }
                
                if ($is404 && $attempt === 1) {
                    try {
                        $this->ensureTranslation($projectSlug, $componentSlug, $weblateLanguage);
                        continue;
                    } catch (Exception $createError) {
                        throw $e;
                    }
                }

                $isTimeout = $e->getCode() === 28
                    || strpos($e->getMessage(), 'cURL error 28') !== false;

                if ($isTimeout && $attempt <= $maxRetries) {
                    echo "      retrying {$language} after timeout (attempt {$attempt} of {$maxRetries})...\n";
                    sleep(5);
                    continue;
                }

                $errorBody = '';
                if (method_exists($e, 'getResponse') && $e->getResponse()) {
                    $errorBody = $e->getResponse()->getBody()->getContents();
                }

                throw new Exception(
                    "Error uploading PO file for {$language}: " .
                        $e->getMessage() .
                        "\n" .
                        $errorBody
                );
            }
        }
    }

    /**
     * Ensure translation exists for a language
     *
     * @param string $projectSlug
     * @param string $componentSlug
     * @param string $language
     * @return void
     * @throws Exception
     */
    public function ensureTranslation($projectSlug, $componentSlug, $language)
    {
        $code = $this->mapLanguageCode($language);

        try {
            $this->client->get("translations/{$projectSlug}/{$componentSlug}/{$code}/");
        } catch (GuzzleException $e) {
            if ($e->getCode() === 404) {
                try {
                    $this->client->post("components/{$projectSlug}/{$componentSlug}/translations/", [
                        'json' => [
                            'language_code' => $code,
                        ]
                    ]);
                } catch (GuzzleException $createError) {
                    $errorBody = '';
                    if (method_exists($createError, 'getResponse') && $createError->getResponse()) {
                        $errorBody = $createError->getResponse()->getBody()->getContents();
                    }
                    throw new Exception("Error creating translation for {$language} (mapped: {$code}): " . $createError->getMessage() . "\n" . $errorBody);
                }
            } else {
                throw new Exception("Error checking translation for {$language} (mapped: {$code}): " . $e->getMessage());
            }
        }
    }

    /**
     * Normalize Plural-Forms header and plural msgstr[...] entries in a PO file
     * to match Weblate expectations for specific languages.
     *
     * @param string $languageCode WordPress language code (e.g., he_IL, ja, yo)
     * @param string $poFilePath   Path to the generated .po file
     * @return void
     */
    private function normalizePluralFormsForWeblate($languageCode, $poFilePath)
    {
        $expected = $this->getWeblatePluralForms($languageCode);
        if (!$expected) {
            return;
        }

        $contents = @file_get_contents($poFilePath);
        if ($contents === false || $contents === '') {
            return;
        }

        if (!preg_match('/msgid\s+""\s+msgstr\s+""(.*?)\n\n/s', $contents, $m)) {
            return;
        }

        $headerBlock = $m[1];

        preg_match_all('/"([^"]*)"/', $headerBlock, $matches);
        $headers = $matches[1];

        $normalizedLanguage = str_replace('-', '_', $languageCode);
        $cleanHeaders = [];
        foreach ($headers as $h) {

            if (stripos($h, 'Plural-Forms:') === 0) {
                continue;
            }

            if (stripos($h, 'Language:') === 0) {
                $cleanHeaders[] = 'Language: ' . $normalizedLanguage . '\n';
                continue;
            }

            $cleanHeaders[] = $h;
        }

        if ($languageCode !== 'ja') {
            $cleanHeaders[] = "Plural-Forms: {$expected}\\n";
        }

        $newHeader = "";
        foreach ($cleanHeaders as $h) {
            $newHeader .= '"' . $h . '"' . "\n";
        }

        $contents = preg_replace(
            '/msgid\s+""\s+msgstr\s+""(.*?)\n\n/s',
            "msgid \"\"\nmsgstr \"\"\n" . $newHeader . "\n",
            $contents,
            1
        );

        $nplurals = 1;
        if (preg_match('/nplurals\s*=\s*(\d+)/', $expected, $m2)) {
            $nplurals = max(1, (int)$m2[1]);
        }

        $lines = explode("\n", $contents);
        $out = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            if (preg_match('/^msgid_plural/', $line)) {
                $out[] = $line;
                $j = $i + 1;

                $vals = [];
                while ($j < $count && preg_match('/^msgstr\[(\d+)\]\s+"(.*)"$/', $lines[$j], $mm)) {
                    $vals[(int)$mm[1]] = $mm[2];
                    $j++;
                }

                for ($idx = 0; $idx < $nplurals; $idx++) {
                    $out[] = 'msgstr[' . $idx . '] "' . ($vals[$idx] ?? '') . '"';
                }

                $i = $j - 1;
                continue;
            }

            $out[] = $line;
        }

        file_put_contents($poFilePath, implode("\n", $out));
    }

    /**
     * Return Weblate's expected Plural-Forms rule for specific languages.
     * Only languages that have caused validation errors are handled here.
     *
     * @param string $languageCode WordPress language code
     * @return string|null
     */
    private function getWeblatePluralForms($languageCode)
    {
        // Map WP codes to Weblate plural rules.
        $map = [
            'he'    => 'nplurals=4; plural=(n == 1 ? 0 : (n == 2 ? 1 : ((n > 10 && n % 10 == 0) ? 2 : 3)));',
            'he_IL' => 'nplurals=4; plural=(n == 1 ? 0 : (n == 2 ? 1 : ((n > 10 && n % 10 == 0) ? 2 : 3)));',
            'ja'    => 'nplurals=1; plural=0;',
            'yo'    => 'nplurals=1; plural=0;',
            'fil'   => 'nplurals=2; plural=n != 1 && n != 2 && n != 3 && (n % 10 == 4 || n % 10 == 6 || n % 10 == 9);',
            'fa'    => 'nplurals=2; plural=(n > 1);',
            'fa_IR' => 'nplurals=2; plural=(n > 1);',
            'fr_FR' => 'nplurals=2; plural=(n > 1);',
            'pt'    => 'nplurals=2; plural=n > 1;',
            'pt_PT' => 'nplurals=2; plural=n > 1;',
            'tr'    => 'nplurals=2; plural=(n != 1);',
            'tr_TR' => 'nplurals=2; plural=(n != 1);',

        ];

        return isset($map[$languageCode]) ? $map[$languageCode] : null;
    }

    /**
     * Download PO file for a language
     *
     * @param string $projectSlug
     * @param string $componentSlug
     * @param string $language
     * @return string|null PO file content or null if not found
     * @throws Exception
     */
    public function downloadPo($projectSlug, $componentSlug, $language)
    {
        $maxRetries = 2;
        $attempt    = 0;

        while (true) {
            try {
                $weblateLanguage = $this->mapLanguageCode($language);

                $response = $this->client->get(
                    "translations/{$projectSlug}/{$componentSlug}/{$weblateLanguage}/file/"
                );

                return $response->getBody()->getContents();

            } catch (GuzzleException $e) {
                $attempt++;

                if ($e->getCode() === 404) {
                    return null;
                }

                $isTimeout = $e->getCode() === 28
                    || strpos($e->getMessage(), 'cURL error 28') !== false;

                if ($isTimeout && $attempt <= $maxRetries) {
                    echo "      retrying download for {$language} after timeout (attempt {$attempt} of {$maxRetries})...\n";
                    sleep(5);
                    continue;
                }

                throw new Exception(
                    "Error downloading PO file for {$language}: " .
                        $e->getMessage()
                );
            }
        }
    }

    /**
     * Get component statistics
     *
     * @param string $projectSlug
     * @param string $componentSlug
     * @return array
     * @throws Exception
     */
    public function getComponentStats($projectSlug, $componentSlug)
    {
        try {
            $response = $this->client->get("components/{$projectSlug}/{$componentSlug}/statistics/");
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new Exception("Error getting component stats: " . $e->getMessage());
        }
    }

    public function getComponentLanguages($projectSlug, $componentSlug)
    {
        $languages = [];
        $url = "components/{$projectSlug}/{$componentSlug}/translations/";

        try {
            while ($url) {
                $response = $this->client->get($url);
                $data = json_decode($response->getBody()->getContents(), true);

                if (isset($data['results']) && is_array($data['results'])) {
                    foreach ($data['results'] as $translation) {
                        if (isset($translation['language_code'])) {
                            $languages[] = $translation['language_code'];
                        } elseif (
                            isset($translation['language']) &&
                            is_array($translation['language']) &&
                            isset($translation['language']['code'])
                        ) {
                            $languages[] = $translation['language']['code'];
                        }
                    }
                }

                // Handle pagination
                if (!empty($data['next'])) {
                    $next = $data['next'];
                    if (strpos($next, $this->apiUrl) === 0) {
                        $url = substr($next, strlen($this->apiUrl));
                    } else {
                        $url = $next;
                    }
                } else {
                    $url = null;
                }
            }

            $languages = array_values(array_unique($languages));

            return $languages;
        } catch (GuzzleException $e) {
            throw new Exception("Error getting component languages: " . $e->getMessage());
        }
    }

}