<?php

/**
 * Batched OpenAI judge for whether a .po msgstr change is worth keeping.
 *
 * @package PublishPress\Translations\Audit\Support
 */

namespace PublishPress\Translations\Audit\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class AiWorthinessJudge
{
    private const MODEL = 'gpt-4o-mini';

    /** USD per 1M tokens (approx list price; cap is still a safety rail). */
    private const PRICE_INPUT_PER_1M  = 0.15;

    private const PRICE_OUTPUT_PER_1M = 0.60;

    /** @var Client */
    private $http;

    /** @var float */
    private $cumulativeUsd = 0.0;

    /** @var array<string,array{worthy:bool,reason:string}> */
    private $cache = [];

    public function __construct()
    {
        $this->http = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'timeout'  => 60,
        ]);
    }

    public function cumulativeCostUsd(): float
    {
        return $this->cumulativeUsd;
    }

    /**
     * @param array<string,array{msgid:string,old:string,new:string}> $batch keyed by stable id
     * @return array<string,array{worthy:bool,reason:string}>
     */
    public function judgeBatch(string $apiKey, string $language, array $batch): array
    {
        if ($batch === []) {
            return [];
        }

        $items = [];
        foreach ($batch as $id => $row) {
            $items[] = [
                'id'    => $id,
                'msgid' => $row['msgid'],
                'old'   => $row['old'],
                'new'   => $row['new'],
            ];
        }

        $userPayload = json_encode(
            [
                'locale'  => $language,
                'changes' => $items,
            ],
            JSON_UNESCAPED_UNICODE
        );

        $payload = [
            'model'       => self::MODEL,
            'temperature' => 0,
            'messages'    => [
                [
                    'role'    => 'system',
                    'content' => 'You judge gettext translation edits. Return ONLY valid JSON: {"judgments":[{"id":"...","worthy":true|false,"reason":"short"}]}. '
                        . 'worthy=true if the new text clearly improves grammar, meaning, terminology, or fixes an error vs the old translation for the given source msgid in the target locale. '
                        . 'worthy=false for cosmetic-only changes (spacing, punctuation, trivial synonym with same meaning). '
                        . 'If unsure, pick worthy=true.',
                ],
                [
                    'role'    => 'user',
                    'content' => $userPayload,
                ],
            ],
        ];

        try {
            $res = $this->http->post('chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'json'    => $payload,
            ]);
            $body = (string) $res->getBody();
            $json = json_decode($body, true);
            if (!is_array($json)) {
                return $this->fallbackWorthy($batch, 'OpenAI response not JSON');
            }

            $usage = $json['usage'] ?? null;
            if (is_array($usage)) {
                $in  = (int) ($usage['prompt_tokens'] ?? 0);
                $out = (int) ($usage['completion_tokens'] ?? 0);
                $this->cumulativeUsd += ($in / 1000000.0) * self::PRICE_INPUT_PER_1M
                    + ($out / 1000000.0) * self::PRICE_OUTPUT_PER_1M;
            }

            $content = $json['choices'][0]['message']['content'] ?? '';
            if (!is_string($content)) {
                return $this->fallbackWorthy($batch, 'missing message content');
            }

            $parsed = json_decode($content, true);
            if (!is_array($parsed) || !isset($parsed['judgments']) || !is_array($parsed['judgments'])) {
                return $this->fallbackWorthy($batch, 'judgments JSON parse failed');
            }

            $outMap = [];
            foreach ($parsed['judgments'] as $row) {
                if (!is_array($row) || !isset($row['id'])) {
                    continue;
                }
                $id = (string) $row['id'];
                if (!isset($batch[$id])) {
                    continue;
                }
                $worthy = true;
                if (array_key_exists('worthy', $row)) {
                    $worthy = (bool) $row['worthy'];
                }
                $reason = isset($row['reason']) ? (string) $row['reason'] : '';
                $outMap[$id] = ['worthy' => $worthy, 'reason' => $reason];
            }

            foreach (array_keys($batch) as $id) {
                if (!isset($outMap[$id])) {
                    $outMap[$id] = ['worthy' => true, 'reason' => 'missing from model output; default keep'];
                }
            }

            return $outMap;
        } catch (GuzzleException $e) {
            return $this->fallbackWorthy($batch, $e->getMessage());
        }
    }

    /**
     * @param array<string,array{msgid:string,old:string,new:string}> $batch
     * @return array<string,array{worthy:bool,reason:string}>
     */
    public function judgeCached(string $apiKey, string $language, array $batch): array
    {
        $todo  = [];
        $ready = [];

        foreach ($batch as $id => $row) {
            $ck = $language . "\n" . $row['msgid'] . "\n" . $row['old'] . "\n" . $row['new'];
            if (isset($this->cache[$ck])) {
                $ready[$id] = $this->cache[$ck];
            } else {
                $todo[$id] = $row;
            }
        }

        if ($todo !== []) {
            $judged = $this->judgeBatch($apiKey, $language, $todo);
            foreach ($judged as $id => $j) {
                $row       = $todo[$id];
                $ck        = $language . "\n" . $row['msgid'] . "\n" . $row['old'] . "\n" . $row['new'];
                $this->cache[$ck] = $j;
                $ready[$id]      = $j;
            }
        }

        return $ready;
    }

    /**
     * @param array<string,array{msgid:string,old:string,new:string}> $batch
     * @return array<string,array{worthy:bool,reason:string}>
     */
    private function fallbackWorthy(array $batch, string $reason): array
    {
        $out = [];
        foreach (array_keys($batch) as $id) {
            $out[$id] = ['worthy' => true, 'reason' => 'fallback keep: ' . $reason];
        }

        return $out;
    }
}
