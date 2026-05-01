<?php

/**
 * Surgical line edits on .po files (avoid full PO rewrite).
 *
 * @package PublishPress\Translations\Audit\Support
 */

namespace PublishPress\Translations\Audit\Support;

use Gettext\Extractors\Po as PoExtractor;
use Gettext\Translation;
use RuntimeException;

final class PoEntrySplicer
{
    /**
     * Replace msgstr / msgstr[n] for one entry identified by context + msgid.
     *
     * @param string|null $plural msgid_plural text if plural entry
     * @param string[]    $pluralMsgstrs msgstr[1..] forms (msgstr[0] is $singularMsgstr)
     */
    public static function replaceEntryTranslations(
        string $path,
        ?string $context,
        string $msgid,
        ?string $plural,
        string $singularMsgstr,
        array $pluralMsgstrs = []
    ): void {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read {$path}");
        }

        $hadCrlf = strpos($raw, "\r\n") !== false;
        $norm    = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines   = explode("\n", $norm);

        $ctx      = $context === null ? '' : $context;
        $targetId = Translation::generateId($ctx, $msgid);

        $logical = self::buildLogicalLines($lines);
        $state   = self::scanForEntry($logical, $targetId, $plural !== null && $plural !== '');

        if ($state === null) {
            throw new RuntimeException("Entry not found in {$path} for msgid splice.");
        }

        $newMiddle = self::buildReplacementMsgstrLines(
            $state['prefix'],
            $plural !== null && $plural !== '',
            $singularMsgstr,
            $pluralMsgstrs
        );

        $before = array_slice($lines, 0, $state['rangeStart']);
        $after  = array_slice($lines, $state['rangeEnd'] + 1);
        $merged = implode("\n", array_merge($before, $newMiddle, $after));
        if ($hadCrlf) {
            $merged = str_replace("\n", "\r\n", $merged);
        }

        self::atomicPut($path, $merged);
    }

    /**
     * Replace Project-Id-Version inside header msgstr (first msgid "" block).
     */
    public static function replaceProjectIdVersion(string $path, string $newHeaderValue): void
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read {$path}");
        }

        $hadCrlf = strpos($raw, "\r\n") !== false;
        $norm    = str_replace(["\r\n", "\r"], "\n", $raw);

        $frag = self::escapeHeaderValueFragment($newHeaderValue);
        $repl = '"Project-Id-Version: ' . $frag . '\\n"';
        $out  = preg_replace('/"Project-Id-Version:.*?\\\\n"/s', $repl, $norm, 1);
        if ($out === null || $out === $norm) {
            $out = preg_replace('/"Project-Id-Version:[^"]*"/', $repl, $norm, 1);
        }

        if ($out === null || $out === $norm) {
            throw new RuntimeException("Project-Id-Version header line not found in {$path}");
        }

        if ($hadCrlf) {
            $out = str_replace("\n", "\r\n", $out);
        }

        self::atomicPut($path, $out);
    }

    private static function escapeHeaderValueFragment(string $v): string
    {
        return strtr(
            $v,
            [
                '\\' => '\\\\',
                '"'  => '\\"',
            ]
        );
    }

    /**
     * @param string[] $lines
     * @return array<int,array{line:string,start:int,end:int}>
     */
    private static function buildLogicalLines(array $lines): array
    {
        $logical = [];
        $n       = count($lines);
        $i       = 0;

        while ($i < $n) {
            $line = trim($lines[$i]);
            if ($line === '#') {
                $line = '';
            }

            $start = $i;
            $j     = $i;

            while ($line !== '' && substr($line, -1) === '"' && $j + 1 < $n) {
                $next = trim($lines[$j + 1]);
                if ($next !== '' && ($next[0] === '"' || substr($next, 0, 4) === '#~ "')) {
                    if ($next[0] === '"') {
                        $line = substr($line, 0, -1) . substr($next, 1);
                    } else {
                        $line = substr($line, 0, -1) . substr($next, 4);
                    }
                    $j++;
                } else {
                    break;
                }
            }

            $logical[] = [
                'line'  => $line,
                'start' => $start,
                'end'   => $j,
            ];
            $i = $j + 1;
        }

        return $logical;
    }

    /**
     * @param array<int,array{line:string,start:int,end:int}> $logical
     * @return array{rangeStart:int,rangeEnd:int,prefix:string}|null
     */
    private static function scanForEntry(array $logical, string $targetId, bool $expectPlural): ?array
    {
        $translation      = new Translation('', '');
        $disabled         = false;
        $prefix           = '';
        $msgstrStart      = null;
        $msgstrEnd        = null;
        $inTargetMsgstr   = false;

        foreach ($logical as $item) {
            $line = $item['line'];

            if ($line === '') {
                if ($inTargetMsgstr && $msgstrStart !== null && $msgstrEnd !== null) {
                    return [
                        'rangeStart' => $msgstrStart,
                        'rangeEnd'   => $msgstrEnd,
                        'prefix'     => $prefix,
                    ];
                }
                $inTargetMsgstr = false;
                $msgstrStart    = null;
                $msgstrEnd      = null;
                $translation    = new Translation('', '');
                $disabled       = false;
                $prefix         = '';
                continue;
            }

            $parts = preg_split('/\s+/', $line, 2);
            if ($parts === false) {
                $parts = ['', ''];
            }
            $key  = $parts[0] ?? '';
            $data = $parts[1] ?? '';

            if ($key === '#~') {
                $disabled = true;
                $parts     = preg_split('/\s+/', $data, 2);
                if ($parts === false) {
                    $parts = ['', ''];
                }
                $key    = $parts[0] ?? '';
                $data   = $parts[1] ?? '';
                $prefix = '#~ ';
            }

            if ($data === '') {
                continue;
            }

            switch ($key) {
                case '#':
                case '#.':
                case '#,':
                case '#:':
                    break;
                case 'msgctxt':
                    $translation = $translation->getClone(PoExtractor::convertString($data), null);
                    break;
                case 'msgid':
                    $translation = $translation->getClone(null, PoExtractor::convertString($data));
                    break;
                case 'msgid_plural':
                    $translation->setPlural(PoExtractor::convertString($data));
                    break;
                default:
                    if ($key === 'msgstr' || strpos($key, 'msgstr[') === 0) {
                        if ($translation->getId() === $targetId && !$disabled) {
                            $pluralOk = $translation->hasPlural();
                            if ($expectPlural !== $pluralOk) {
                                break;
                            }

                            if (!$inTargetMsgstr) {
                                $inTargetMsgstr = true;
                                $msgstrStart    = $item['start'];
                            }
                            $msgstrEnd = $item['end'];
                        }
                    }
                    break;
            }
        }

        if ($inTargetMsgstr && $msgstrStart !== null && $msgstrEnd !== null) {
            return [
                'rangeStart' => $msgstrStart,
                'rangeEnd'   => $msgstrEnd,
                'prefix'     => $prefix,
            ];
        }

        return null;
    }

    /**
     * @param string[] $pluralMsgstrs
     * @return string[]
     */
    private static function buildReplacementMsgstrLines(
        string $prefix,
        bool $plural,
        string $singularMsgstr,
        array $pluralMsgstrs
    ): array {
        $lines = [];
        if (!$plural) {
            foreach (PoFormat::directiveLines($prefix, 'msgstr', $singularMsgstr) as $l) {
                $lines[] = $l;
            }

            return $lines;
        }

        foreach (PoFormat::directiveLines($prefix, 'msgstr[0]', $singularMsgstr) as $l) {
            $lines[] = $l;
        }

        $i = 1;
        foreach ($pluralMsgstrs as $p) {
            foreach (PoFormat::directiveLines($prefix, 'msgstr[' . $i . ']', (string) $p) as $l) {
                $lines[] = $l;
            }
            $i++;
        }

        return $lines;
    }

    private static function atomicPut(string $path, string $contents): void
    {
        $dir = dirname($path);
        $tmp = $dir . '/.' . basename($path) . '.' . uniqid('tmp', true);
        if (@file_put_contents($tmp, $contents) === false) {
            throw new RuntimeException("Cannot write temp file {$tmp}");
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot replace {$path}");
        }
    }
}
