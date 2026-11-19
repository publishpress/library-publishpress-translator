<?php

/**
 * Rewrite .po msgstr values whose printf placeholders cannot be passed to sprintf.
 *
 * A count mismatch keeps the translated words. Extra placeholders are removed.
 * Missing placeholders are appended. The sentence stays in the target language.
 *
 * @package PublishPress\Translations\Support
 */

namespace PublishPress\Translations\Support;

use Gettext\Translation;
use PublishPress\Translations\Audit\Support\PoEntrySplicer;
use PublishPress\Translations\Audit\Support\PoFile;

final class PoFormatRepair
{
    /**
     * @return array<int,array{msgid:string,action:string,reason:string,before:string,after:string}>
     */
    public static function repairFile(string $path): array
    {
        $po      = PoFile::fromFile($path, false);
        $changes = [];

        foreach ($po->activeTranslations() as $translation) {
            $change = self::repairEntry($path, $translation);
            if ($change !== null) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    /**
     * @return array{msgid:string,action:string,reason:string,before:string,after:string}|null
     */
    private static function repairEntry(string $path, Translation $translation): ?array
    {
        $msgid   = $translation->getOriginal();
        $plural  = $translation->hasPlural() ? $translation->getPlural() : '';
        $forms    = self::forms($translation);
        $updated  = $forms;
        $reasons  = [];
        $fellBack = false;
        $changed  = false;

        foreach ($forms as $index => $value) {
            if ($value === '') {
                continue;
            }

            $source = self::sourceForForm($msgid, $plural, (int) $index);
            $result = PrintfPlaceholder::align($source, $value);
            if ($result['action'] === 'ok' || $result['action'] === 'skip') {
                continue;
            }

            $updated[$index] = $result['value'];
            $reasons[]       = $result['reason'];
            if ($result['action'] === 'fallback') {
                $fellBack = true;
            }
            $changed = true;
        }

        if (!$changed) {
            return null;
        }

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
                'before' => self::join($forms),
                'after'  => self::join($updated),
            ];
        }

        return [
            'msgid'  => $msgid,
            'action' => $fellBack ? 'fallback' : 'rewritten',
            'reason' => implode('; ', $reasons),
            'before' => self::join($forms),
            'after'  => self::join($updated),
        ];
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
