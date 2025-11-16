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
        $this->apiUrl = $apiUrl ?: getenv('WEBLATE_API_URL') ?: 'https://hosted.weblate.org/api/';
        $this->apiToken = $apiToken ?: getenv('WEBLATE_API_TOKEN');
        
        if (!$this->apiToken) {
            throw new Exception(
                "Weblate API token not found.\n" .
                "Please set WEBLATE_API_TOKEN environment variable.\n" .
                "Get your token from: https://hosted.weblate.org/accounts/profile/#api"
            );
        }
        
        $this->apiUrl = rtrim($this->apiUrl, '/') . '/';
        
        $this->client = new Client([
            'base_uri' => $this->apiUrl,
            'headers' => [
                'Authorization' => 'Token ' . $this->apiToken,
                'Accept' => 'application/json',
            ],
            'timeout' => 3600,
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
    public function createProject($projectSlug, $projectName)
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
            throw new Exception("Error creating project: " . $e->getMessage());
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
        try {
            $potContent = file_get_contents($potFilePath);
            if ($potContent === false) {
                throw new Exception("Failed to read POT file: {$potFilePath}");
            }
            
            $repoType = getenv('WEBLATE_REPO_TYPE') ?: 'https';

            if ($gitRepoSlug && preg_match('#^https?://#', $gitRepoSlug)) {
                $repoUrl = $gitRepoSlug;
                $pushUrl = ($repoType === 'ssh') ? $gitRepoSlug : '';
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
            
            $response = $this->client->post("projects/{$projectSlug}/components/", [
                'json' => [
                    'name' => $componentName,
                    'slug' => $componentSlug,
                    'repo' => $repoUrl,
                    'branch' => 'development',
                    'push' => $pushUrl,
                    'vcs' => 'git',
                    'file_format' => 'po',
                    'filemask' => "languages/{$componentSlug}-*.po",
                    'new_base' => "languages/{$componentSlug}.pot",
                    'new_lang' => 'add',
                    'manage_units' => false,
                    'update_on_commit' => false,
                ]
            ]);
            
            $result = json_decode($response->getBody()->getContents(), true);
            return $result;
        } catch (GuzzleException $e) {
            $errorBody = '';
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $errorBody = $e->getResponse()->getBody()->getContents();
            }
            throw new Exception("Error creating component: " . $e->getMessage() . "\n" . $errorBody);
        }
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
            
            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new Exception("Error uploading POT file: " . $e->getMessage());
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
        // Special mappings that don't follow the standard pattern
        $specialMappings = [
            'zh_CN' => 'zh_Hans',
            'zh_TW' => 'zh_Hant',
            'fil' => 'fil',
            'yo' => 'yo',
            'en_GB' => 'en_GB',
            'pt_BR' => 'pt_BR',
            'sr_RS' => 'sr_RS',
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
        try {
            $weblateLanguage = $this->mapLanguageCode($language);

            $this->normalizePluralFormsForWeblate($language, $poFilePath);
            
            $this->ensureTranslation($projectSlug, $componentSlug, $weblateLanguage);
            
            $response = $this->client->post(
                "translations/{$projectSlug}/{$componentSlug}/{$weblateLanguage}/file/",
                [
                    'multipart' => [
                        [
                            'name' => 'file',
                            'contents' => fopen($poFilePath, 'r'),
                        ],
                        [
                            'name' => 'method',
                            'contents' => 'translate',
                        ],
                    ],
                ]
            );
            
            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            $errorBody = '';
            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $errorBody = $e->getResponse()->getBody()->getContents();
            }
            throw new Exception("Error uploading PO file for {$language}: " . $e->getMessage() . "\n" . $errorBody);
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
    private function ensureTranslation($projectSlug, $componentSlug, $language)
    {
        try {
            $this->client->get("translations/{$projectSlug}/{$componentSlug}/{$language}/");
        } catch (GuzzleException $e) {
            if ($e->getCode() === 404) {
                try {
                    $this->client->post("components/{$projectSlug}/{$componentSlug}/translations/", [
                        'json' => [
                            'language_code' => $language,
                        ]
                    ]);
                } catch (GuzzleException $createError) {
                    $errorBody = '';
                    if (method_exists($createError, 'getResponse') && $createError->getResponse()) {
                        $errorBody = $createError->getResponse()->getBody()->getContents();
                    }
                    throw new Exception("Error creating translation for {$language}: " . $createError->getMessage() . "\n" . $errorBody);
                }
            } else {
                throw new Exception("Error checking translation for {$language}: " . $e->getMessage());
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

        if ($languageCode === 'ja') {
            $contents = preg_replace(
                '/"Plural-Forms:[^"]*\\\\n"\s*\r?\n?/',
                '',
                $contents,
                1
            );
        } else {
            $expectedLine = '"Plural-Forms: ' . $expected . "\\n" . '"';

            if (strpos($contents, 'Plural-Forms:') !== false) {
                $contents = preg_replace(
                    '/"Plural-Forms:[^"]*\\\\n"/',
                    $expectedLine,
                    $contents,
                    1
                );
            } else {
                if (strpos($contents, 'Language:') !== false) {
                    $contents = preg_replace(
                        '/("Language:[^"\\n]*\\n")/',
                        "$1\n" . $expectedLine,
                        $contents,
                        1
                    );
                } else {
                    @file_put_contents($poFilePath, $contents);
                    return;
                }
            }
        }

        $nplurals = 1;
        if (preg_match('/nplurals\s*=\s*(\d+)/', $expected, $m)) {
            $nplurals = max(1, (int) $m[1]);
        }

        $lines     = preg_split("/(\r\n|\n|\r)/", $contents);
        $newLines  = [];
        $lineCount = count($lines);

        for ($i = 0; $i < $lineCount; $i++) {
            $line = $lines[$i];

            if (preg_match('/^msgid_plural\s+"(.+)"$/', $line)) {

                if ($languageCode === 'ja') {
                    $j = $i + 1;

                    while (
                        $j < $lineCount &&
                        !preg_match('/^msgstr\[\d+\]\s+"/', $lines[$j]) &&
                        !preg_match('/^msgid\s+"/', $lines[$j])
                    ) {
                        $j++;
                    }

                    $translation = '';
                    if (
                        $j < $lineCount &&
                        preg_match('/^msgstr\[\d+\]\s+"(.*)"$/', $lines[$j], $mStr)
                    ) {
                        $translation = $mStr[1];
                    }

                    $newLines[] = 'msgstr "' . $translation . '"';

                    $k = $j + 1;
                    while (
                        $k < $lineCount &&
                        preg_match('/^msgstr\[\d+\]\s+"/', $lines[$k])
                    ) {
                        $k++;
                    }

                    $i = $k - 1;
                    continue;
                }

                $newLines[] = $line;
                $j = $i + 1;

                while (
                    $j < $lineCount &&
                    !preg_match('/^msgstr\[\d+\]\s+"/', $lines[$j]) &&
                    !preg_match('/^msgid\s+"/', $lines[$j])
                ) {
                    $newLines[] = $lines[$j];
                    $j++;
                }

                if ($j >= $lineCount || !preg_match('/^msgstr\[\d+\]\s+"/', $lines[$j])) {
                    $i = $j - 1;
                    continue;
                }

                $values = [];
                $k      = $j;

                while (
                    $k < $lineCount &&
                    preg_match('/^msgstr\[(\d+)\]\s+"(.*)"$/', $lines[$k], $mStr)
                ) {
                    $values[(int) $mStr[1]] = $mStr[2];
                    $k++;
                }

                for ($idx = 0; $idx < $nplurals; $idx++) {
                    $val = isset($values[$idx]) ? $values[$idx] : '';
                    $newLines[] = 'msgstr[' . $idx . '] "' . $val . '"';
                }

                $i = $k - 1;
                continue;
            }

            $newLines[] = $line;
        }

        $normalized = implode("\n", $newLines);
        @file_put_contents($poFilePath, $normalized);
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
        try {
            $weblateLanguage = $this->mapLanguageCode($language);
            
            $response = $this->client->get(
                "translations/{$projectSlug}/{$componentSlug}/{$weblateLanguage}/file/"
            );
            
            return $response->getBody()->getContents();
        } catch (GuzzleException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw new Exception("Error downloading PO file for {$language}: " . $e->getMessage());
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
}