<?php

/**
 * Ask OpenAI for a target-language translation whose printf placeholders match the source.
 *
 * A reply is kept only when PrintfPlaceholder accepts it. A bad reply is discarded.
 *
 * Called from PoFormatRepair::repairFile(), which Translator::repairPoFormatStrings() runs.
 * No other class rewrites a mismatched msgstr. AiWorthinessJudge only scores edits.
 *
 * Request JSON: {"locale":"da_DK","items":[{"id":"1","source":"...","current":"..."}]}
 * Response JSON: {"items":[{"id":"1","translation":"..."}]}
 * No date fields. The API key stays in the Authorization header and is not stored.
 *
 * @package PublishPress\Translations\Support
 */

namespace PublishPress\Translations\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class AiFormatStringFixer
{
    private const MODEL = 'gpt-4o-mini';

    /** Same list price as the audit judge for gpt-4o-mini. */
    private const PRICE_INPUT_PER_1M = 0.15;

    private const PRICE_OUTPUT_PER_1M = 0.60;

    /** @var Client */
    private $http;

    /** @var float */
    private $maxUsd;

    /** @var float */
    private $spentUsd = 0.0;

    /** @var bool */
    private $capped = false;

    public function __construct(float $maxUsd)
    {
        $this->maxUsd = $maxUsd;
        $this->http   = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'timeout'  => 90,
        ]);
    }

    public function capped(): bool
    {
        return $this->capped;
    }

    public function spentUsd(): float
    {
        return $this->spentUsd;
    }

    /**
     * @param array<int,array{id:string,source:string,current:string}> $jobs
     * @return array<string,string> id => accepted translation
     */
    public function fix(string $apiKey, string $locale, array $jobs): array
    {
        if ($jobs === [] || $apiKey === '' || $this->atCap()) {
            return [];
        }

        $accepted = $this->runChunks($apiKey, $locale, $jobs);

        $retry = [];
        foreach ($jobs as $job) {
            if (!isset($accepted[$job['id']])) {
                $retry[] = $job;
            }
        }
        if ($retry !== []) {
            $accepted += $this->runChunks($apiKey, $locale, $retry);
        }

        return $accepted;
    }

    /**
     * @param array<int,array{id:string,source:string,current:string}> $jobs
     * @return array<string,string>
     */
    private function runChunks(string $apiKey, string $locale, array $jobs): array
    {
        $accepted = [];
        $chunks   = array_chunk($jobs, 12);
        foreach ($chunks as $chunk) {
            if ($this->atCap()) {
                break;
            }
            $accepted += $this->request($apiKey, $locale, $chunk);
        }

        return $accepted;
    }

    private function atCap(): bool
    {
        if ($this->spentUsd >= $this->maxUsd) {
            $this->capped = true;

            return true;
        }

        return false;
    }

    /**
     * @param array<int,array{id:string,source:string,current:string}> $jobs
     * @return array<string,string>
     */
    private function request(string $apiKey, string $locale, array $jobs): array
    {
        $items = [];
        foreach ($jobs as $job) {
            $items[] = [
                'id'      => $job['id'],
                'source'  => $job['source'],
                'current' => $job['current'],
            ];
        }

        $user = json_encode(
            [
                'locale' => $locale,
                'items'  => $items,
            ],
            JSON_UNESCAPED_UNICODE
        );
        if (!is_string($user)) {
            return [];
        }

        $system = 'You repair WordPress gettext translations. '
            . 'Return only JSON: {"items":[{"id":"...","translation":"..."}]}. '
            . 'Write translation in the user JSON locale. It must mean the same as source. '
            . 'Use current only when it is already that sentence in the locale. '
            . 'Copy every printf placeholder from source exactly (%s, %1$s, %2$d, %%). '
            . 'Numbered placeholders may change order. Do not add, remove, or change one. '
            . 'Keep HTML tags. Do not answer in English unless the locale is English.';

        try {
            $res = $this->http->post('chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json'    => [
                    'model'           => self::MODEL,
                    'temperature'     => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages'        => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                ],
            ]);
        } catch (GuzzleException $e) {
            return [];
        }

        $body = json_decode((string) $res->getBody(), true);
        $this->addUsage(is_array($body) ? $body : null);
        if (!is_array($body)) {
            return [];
        }
        $content = $body['choices'][0]['message']['content'] ?? '';
        if (!is_string($content)) {
            return [];
        }

        $parsed = json_decode(self::stripFence($content), true);
        if (!is_array($parsed) || !isset($parsed['items']) || !is_array($parsed['items'])) {
            return [];
        }

        $byId = [];
        foreach ($jobs as $job) {
            $byId[$job['id']] = $job;
        }

        $accepted = [];
        foreach ($parsed['items'] as $row) {
            if (!is_array($row) || !isset($row['id'], $row['translation'])) {
                continue;
            }
            $id = (string) $row['id'];
            if (!isset($byId[$id]) || !is_string($row['translation'])) {
                continue;
            }
            $result = PrintfPlaceholder::align($byId[$id]['source'], $row['translation']);
            if ($result['action'] === 'mismatch' || $result['action'] === 'skip') {
                continue;
            }
            $accepted[$id] = $result['value'];
        }

        return $accepted;
    }

    private static function stripFence(string $content): string
    {
        $content = trim($content);
        if (strpos($content, '```') !== 0) {
            return $content;
        }
        $content = preg_replace('/^```(?:json)?\s*/', '', $content);
        if (!is_string($content)) {
            return '';
        }
        $content = preg_replace('/\s*```$/', '', $content);

        return is_string($content) ? trim($content) : '';
    }

    /**
     * @param array<string,mixed>|null $body
     */
    private function addUsage(?array $body): void
    {
        if ($body === null) {
            return;
        }
        $usage = $body['usage'] ?? null;
        if (!is_array($usage)) {
            return;
        }
        $in  = (int) ($usage['prompt_tokens'] ?? 0);
        $out = (int) ($usage['completion_tokens'] ?? 0);
        $this->spentUsd += ($in / 1000000.0) * self::PRICE_INPUT_PER_1M
            + ($out / 1000000.0) * self::PRICE_OUTPUT_PER_1M;
        if ($this->spentUsd >= $this->maxUsd) {
            $this->capped = true;
        }
    }
}
