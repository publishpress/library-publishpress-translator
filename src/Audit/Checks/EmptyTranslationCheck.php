<?php

/**
 * Empty msgstr / fuzzy counts per locale .po (read-only).
 *
 * @package PublishPress\Translations\Audit\Checks
 */

namespace PublishPress\Translations\Audit\Checks;

use PublishPress\Translations\Audit\AuditCheckInterface;
use PublishPress\Translations\Audit\AuditContext;
use PublishPress\Translations\Audit\AuditFinding;
use PublishPress\Translations\Audit\CheckId;
use PublishPress\Translations\Audit\Support\PoFile;

final class EmptyTranslationCheck implements AuditCheckInterface
{
    public function id(): string
    {
        return CheckId::EMPTY_TRANSLATION;
    }

    public function title(): string
    {
        return 'Empty and fuzzy translations (.po)';
    }

    public function run(AuditContext $ctx): array
    {
        $findings = [];
        $strict   = $ctx->options()->strictPo();
        $dir      = $ctx->languagesDir();

        foreach ($ctx->targetLanguages() as $locale) {
            $pattern = $dir . '/*-' . $locale . '.po';
            $files     = glob($pattern) ?: [];
            foreach ($files as $file) {
                $rel = ltrim(str_replace($ctx->pluginRoot(), '', $file), '/\\');
                try {
                    $po = PoFile::fromFile($file, $strict);
                } catch (\Throwable $e) {
                    $findings[] = new AuditFinding(
                        $this->id(),
                        'warning',
                        $rel,
                        $locale,
                        'Parse error: ' . $e->getMessage(),
                        null,
                        null,
                        null
                    );
                    continue;
                }

                $w = $po->parseWarning();
                if ($w !== null) {
                    $findings[] = new AuditFinding($this->id(), 'warning', $rel, $locale, $w, null, null, null);
                }

                $empty = $po->untranslatedEntries();
                $fuzzy = $po->fuzzyEntries();
                if ($empty === [] && $fuzzy === []) {
                    continue;
                }

                $sampleEmpty = self::sampleMsgids($empty, 8);
                $sampleFuzzy = self::sampleMsgids($fuzzy, 4);

                $msg = sprintf(
                    'empty=%d fuzzy=%d',
                    count($empty),
                    count($fuzzy)
                );
                if ($sampleEmpty !== '') {
                    $msg .= ' | empty msgid sample: ' . $sampleEmpty;
                }
                if ($sampleFuzzy !== '') {
                    $msg .= ' | fuzzy msgid sample: ' . $sampleFuzzy;
                }

                $findings[] = new AuditFinding(
                    $this->id(),
                    count($empty) > 0 ? 'warning' : 'info',
                    $rel,
                    $locale,
                    $msg,
                    null,
                    null,
                    null
                );
            }
        }

        return $findings;
    }

    /**
     * @param array<int,\Gettext\Translation> $entries
     */
    private static function sampleMsgids(array $entries, int $max): string
    {
        $bits = [];
        foreach (array_slice($entries, 0, $max) as $t) {
            $c = $t->getContext();
            $m = $t->getOriginal();
            $bits[] = ($c !== null && $c !== '' ? '[' . $c . '] ' : '') . self::shorten($m);
        }

        return implode('; ', $bits);
    }

    private static function shorten(string $s): string
    {
        if (strlen($s) <= 80) {
            return $s;
        }

        return substr($s, 0, 77) . '...';
    }
}
