<?php

/**
 * Batched OpenAI judge for whether a .po msgstr change is worth keeping.
 *
 * Optional env `PLUGIN_AI_CONTEXT`: short maintainer description of the
 * plugin under audit. When set, sent as `plugin_context` in the user JSON; omitted when unset.
 *
 * @package PublishPress\Translations\Audit\Support
 */

namespace PublishPress\Translations\Audit\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class AiWorthinessJudge
{
    /** Maintainer-supplied blurb for the plugin under audit (optional). */
    private const ENV_AI_PLUGIN_CONTEXT = 'PLUGIN_AI_CONTEXT';

    /** Cap env value size (bytes) before send + cache fingerprint. */
    private const AI_PLUGIN_CONTEXT_MAX_BYTES = 4000;

    /** Cap each en_US back-translation gloss when appended to `reason` (bytes). */
    private const EN_US_GLOSS_MAX_BYTES = 280;

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

        $userBody = [
            'locale'  => $language,
            'changes' => $items,
        ];
        $pluginContext = self::pluginContextFromEnv();
        if ($pluginContext !== null) {
            $userBody['plugin_context'] = $pluginContext;
        }

        $userPayload = json_encode($userBody, JSON_UNESCAPED_UNICODE);

        // Caveman-compressed system prompt (fewer tokens, same rules).
        $systemContent = 'PublishPress = WP publishing plugins (editorial, schedule, perms, revisions, authors, blocks, capabilities, checklists, series, statuses, shortlinks). '
            . 'Judge gettext .po msgstr edits. '
            . 'Each judgment: old_en_us + new_en_us = short en_US gloss of old/new msgstr (back-trans from user JSON locale) → show semantic drift vs msgid. '
            . 'locale=en_US: gloss = trimmed msgstr; both fields still required. '
            . 'ONLY JSON: {"judgments":[{"id":"...","worthy":true|false,"reason":"short","old_en_us":"...","new_en_us":"..."}]}. '
            . 'worthy=true: real gain—grammar/meaning/terms/error vs old for msgid+locale; clearer WP/publish wording OK. '
            . 'worthy=false: cosmetic (space/punct/trivial synonym). Unsure→worthy=true.';
        if ($pluginContext !== null) {
            $systemContent .= ' plugin_context in user JSON = maintainer blurb—weigh terms + domain fit.';
        }

        $payload = [
            'model'       => self::MODEL,
            'temperature' => 0,
            'messages'    => [
                [
                    'role'    => 'system',
                    'content' => $systemContent,
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
                $reason = self::appendEnUsGlossToReason($reason, $row);
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
        $todo       = [];
        $ready      = [];
        $cachePrefix = self::pluginContextCachePrefix();

        foreach ($batch as $id => $row) {
            $ck = $cachePrefix . $language . "\n" . $row['msgid'] . "\n" . $row['old'] . "\n" . $row['new'];
            if (isset($this->cache[$ck])) {
                $ready[$id] = $this->cache[$ck];
            } else {
                $todo[$id] = $row;
            }
        }

        if ($todo !== []) {
            $judged = $this->judgeBatch($apiKey, $language, $todo);
            foreach ($judged as $id => $j) {
                $row = $todo[$id];
                $ck  = $cachePrefix . $language . "\n" . $row['msgid'] . "\n" . $row['old'] . "\n" . $row['new'];
                $this->cache[$ck] = $j;
                $ready[$id]       = $j;
            }
        }

        return $ready;
    }

    /**
     * @param array<string,mixed> $judgmentRow
     */
    private static function appendEnUsGlossToReason(string $reason, array $judgmentRow): string
    {
        $oldEn = isset($judgmentRow['old_en_us']) ? trim((string) $judgmentRow['old_en_us']) : '';
        $newEn = isset($judgmentRow['new_en_us']) ? trim((string) $judgmentRow['new_en_us']) : '';
        if ($oldEn === '' && $newEn === '') {
            return $reason;
        }
        if (strlen($oldEn) > self::EN_US_GLOSS_MAX_BYTES) {
            $oldEn = substr($oldEn, 0, self::EN_US_GLOSS_MAX_BYTES) . '...';
        }
        if (strlen($newEn) > self::EN_US_GLOSS_MAX_BYTES) {
            $newEn = substr($newEn, 0, self::EN_US_GLOSS_MAX_BYTES) . '...';
        }
        $suffix = '[en_US gloss: ' . $oldEn . ' -> ' . $newEn . ']';

        return trim($reason) === '' ? $suffix : trim($reason) . ' ' . $suffix;
    }

    private static function pluginContextFromEnv(): ?string
    {
        $raw = getenv(self::ENV_AI_PLUGIN_CONTEXT);
        if ($raw === false) {
            return null;
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        if (strlen($s) > self::AI_PLUGIN_CONTEXT_MAX_BYTES) {
            $s = substr($s, 0, self::AI_PLUGIN_CONTEXT_MAX_BYTES);
        }

        return $s;
    }

    /**
     * Isolate in-memory cache when optional plugin_context differs.
     */
    private static function pluginContextCachePrefix(): string
    {
        $ctx = self::pluginContextFromEnv();

        return $ctx === null ? '' : (sha1($ctx) . "\n");
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
