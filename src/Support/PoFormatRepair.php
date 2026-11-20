<?php

/**
 * Rewrite .po msgstr values whose printf placeholders do not match the source.
 *
 * Spacing, quote escapes, and same-count renumber stay local.
 * A count or type mismatch is sent to OpenAI. The reply is saved only when
 * its placeholders match. The original sentence stays when the reply does not.
 *
 * @package PublishPress\Translations\Support
 */

namespace PublishPress\Translations\Support;

use Gettext\Translation;
use PublishPress\Translations\Audit\Support\PoEntrySplicer;
use PublishPress\Translations\Audit\Support\PoFile;
use PublishPress\Translations\Output;

final class PoFormatRepair
{
    /**
     * @return array<int,array{msgid:string,action:string,reason:string,before:string,after:string}>
     */
    public static function repairFile(
        string $path,
        ?string $apiKey = null,
        ?Output $output = null,
        ?AiFormatStringFixer $fixer = null
    ): array {
        $po     = PoFile::fromFile($path, false);
        $locale = self::localeFor($path, $po);
        $plans  = [];
        $jobs   = [];
        $nextId = 1;

        foreach ($po->activeTranslations() as $translation) {
            $plan = self::planEntry($translation, $nextId);
            if ($plan === null) {
                continue;
            }
            $nextId = $plan['nextId'];
            $plans[] = $plan;
            foreach ($plan['jobs'] as $job) {
                $jobs[] = $job;
            }
        }

        $fixed = [];
        if ($jobs !== [] && $apiKey !== null && $apiKey !== '') {
            if ($fixer === null) {
                $fixer = new AiFormatStringFixer(5.0);
            }
            if ($fixer->capped()) {
                if ($output !== null) {
                    $output->warning('OpenAI cost cap reached. Placeholder repairs were skipped for ' . $locale . '.');
                }
            } else {
                if ($output !== null) {
                    $output->step('Asking OpenAI for ' . count($jobs) . ' string(s) in ' . $locale);
                }
                $fixed = $fixer->fix($apiKey, $locale, $jobs);
            }
        }

        $changes = [];
        foreach ($plans as $plan) {
            $change = self::applyPlan($path, $plan, $fixed, $apiKey, $fixer);
            if ($change !== null) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    /**
     * @return array{translation:Translation,forms:string[],updated:string[],reasons:string[],localChanged:bool,jobs:array<int,array{id:string,index:int,source:string,current:string}>,nextId:int}|null
     */
    private static function planEntry(Translation $translation, int $nextId): ?array
    {
        $msgid  = $translation->getOriginal();
        $plural = $translation->hasPlural() ? $translation->getPlural() : '';
        $forms  = self::forms($translation);
        $updated = $forms;
        $reasons = [];
        $jobs    = [];
        $local   = false;
        $mismatch = false;

        foreach ($forms as $index => $value) {
            if ($value === '') {
                continue;
            }
            $source = self::sourceForForm($msgid, $plural, (int) $index);
            $result = PrintfPlaceholder::align($source, $value);
            if ($result['action'] === 'ok' || $result['action'] === 'skip') {
                continue;
            }
            if ($result['action'] === 'mismatch') {
                $jobs[] = [
                    'id'      => (string) $nextId,
                    'index'   => (int) $index,
                    'source'  => $source,
                    'current' => $value,
                ];
                $nextId++;
                $mismatch = true;
                continue;
            }
            $updated[$index] = $result['value'];
            $reasons[]       = $result['reason'];
            $local           = true;
        }

        if (!$local && !$mismatch) {
            return null;
        }

        return [
            'translation'  => $translation,
            'forms'        => $forms,
            'updated'      => $updated,
            'reasons'      => $reasons,
            'localChanged' => $local,
            'jobs'         => $jobs,
            'nextId'       => $nextId,
        ];
    }

    /**
     * @param array{translation:Translation,forms:string[],updated:string[],reasons:string[],localChanged:bool,jobs:array<int,array{id:string,index:int,source:string,current:string}>} $plan
     * @param array<string,string> $fixed
     * @return array{msgid:string,action:string,reason:string,before:string,after:string}
     */
    private static function applyPlan(
        string $path,
        array $plan,
        array $fixed,
        ?string $apiKey,
        ?AiFormatStringFixer $fixer
    ): array {
        $translation = $plan['translation'];
        $updated     = $plan['updated'];
        $reasons     = $plan['reasons'];
        $changed     = $plan['localChanged'];
        $failed      = false;

        foreach ($plan['jobs'] as $job) {
            if (!isset($fixed[$job['id']])) {
                $failed = true;
                $reasons[] = self::missReason($apiKey, $fixer);
                continue;
            }
            $updated[$job['index']] = $fixed[$job['id']];
            $reasons[] = 'retranslated in the target language';
            $changed = true;
        }

        $msgid = $translation->getOriginal();
        if (!$changed) {
            return [
                'msgid'  => $msgid,
                'action' => 'failed',
                'reason' => implode('; ', $reasons),
                'before' => self::join($plan['forms']),
                'after'  => self::join($plan['forms']),
            ];
        }

        $plural  = $translation->hasPlural() ? $translation->getPlural() : '';
        $context = $translation->getContext();
        $context = $context === '' ? null : $context;
        $singular = (string) $updated[0];
        $rest     = $plural !== '' ? array_slice($updated, 1) : [];

        try {
            PoEntrySplicer::replaceEntryTranslations(
                $path,
                $context,
                $msgid,
                $plural !== '' ? $plural : null,
                $singular,
                $rest
            );
        } catch (\Throwable $e) {
            return [
                'msgid'  => $msgid,
                'action' => 'failed',
                'reason' => $e->getMessage(),
                'before' => self::join($plan['forms']),
                'after'  => self::join($updated),
            ];
        }

        return [
            'msgid'  => $msgid,
            'action' => $failed ? 'partial' : 'rewritten',
            'reason' => implode('; ', $reasons),
            'before' => self::join($plan['forms']),
            'after'  => self::join($updated),
        ];
    }

    private static function missReason(?string $apiKey, ?AiFormatStringFixer $fixer): string
    {
        if ($apiKey === null || $apiKey === '') {
            return 'OPENAI_API_KEY missing; left the translation unchanged';
        }
        if ($fixer !== null && $fixer->capped()) {
            return 'OpenAI cost cap reached; left the translation unchanged';
        }

        return 'model reply did not match placeholders; left the translation unchanged';
    }

    private static function localeFor(string $path, PoFile $po): string
    {
        $header = $po->header('Language');
        if (is_string($header) && $header !== '') {
            return $header;
        }

        $base = basename($path);
        if (preg_match('/-([A-Za-z]{2,3}(?:_[A-Za-z0-9]+)?)\.po$/', $base, $match) === 1) {
            return $match[1];
        }

        return 'und';
    }

    /**
     * @return string[]
     */
    private static function forms(Translation $translation): array
    {
        $forms = [(string) $translation->getTranslation()];
        if (!$translation->hasPlural()) {
            return $forms;
        }

        foreach ($translation->getPluralTranslations() as $form) {
            $forms[] = (string) $form;
        }

        return $forms;
    }

    private static function sourceForForm(string $msgid, string $plural, int $index): string
    {
        if ($index === 0 || $plural === '') {
            return $msgid;
        }

        return $plural;
    }

    /**
     * @param string[] $forms
     */
    private static function join(array $forms): string
    {
        return implode("\n", $forms);
    }
}
