<?php

/**
 * Detects translations that are identical to their source strings.
 *
 * @package PublishPress\Translations\Audit\Checks
 */

namespace PublishPress\Translations\Audit\Checks;

use Gettext\Translation;
use PublishPress\Translations\Audit\AuditCheckInterface;
use PublishPress\Translations\Audit\AuditContext;
use PublishPress\Translations\Audit\AuditFinding;
use PublishPress\Translations\Audit\CheckId;
use PublishPress\Translations\Audit\IssueSlug;
use PublishPress\Translations\Audit\Support\PoFile;
use PublishPress\Translations\Support\IdenticalTranslationPolicy;

final class IdenticalTranslationCheck implements AuditCheckInterface
{
    public function id(): string
    {
        return CheckId::IDENTICAL_TRANSLATION;
    }

    public function title(): string
    {
        return 'Translations identical to source (.po)';
    }

    public function run(AuditContext $ctx): array
    {
        $findings = [];
        $strict   = $ctx->options()->strictPo();
        $dir      = $ctx->languagesDir();

        foreach ($ctx->targetLanguages() as $locale) {
            $policy = new IdenticalTranslationPolicy($locale, (string) $ctx->pluginExclusionName());
            $files  = glob($dir . '/*-' . $locale . '.po') ?: [];

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
                        null,
                        null,
                        null,
                        IssueSlug::PO_PARSE_ERROR
                    );
                    continue;
                }

                $warning = $po->parseWarning();
                if ($warning !== null) {
                    $findings[] = new AuditFinding(
                        $this->id(),
                        'warning',
                        $rel,
                        $locale,
                        $warning,
                        null,
                        null,
                        null,
                        null,
                        null,
                        IssueSlug::PO_PARSE_WARNING
                    );
                }

                $identical = [];
                foreach ($po->activeTranslations() as $translation) {
                    if ($this->hasUnprotectedIdenticalValue($translation, $policy)) {
                        $identical[] = $translation;
                    }
                }

                if ($identical === []) {
                    continue;
                }

                $detailLines = self::msgidLinesForReport($identical);
                $summary     = 'identical=' . count($identical);
                $previewLines = [];
                foreach (array_slice($detailLines, 0, 8) as $detailLine) {
                    $previewLines[] = self::shorten($detailLine);
                }
                $preview = implode('; ', $previewLines);
                $message     = $summary;
                if ($preview !== '') {
                    $message .= ' | identical msgid sample: ' . $preview;
                }

                $findings[] = new AuditFinding(
                    $this->id(),
                    'warning',
                    $rel,
                    $locale,
                    $message,
                    null,
                    null,
                    null,
                    $detailLines,
                    $summary,
                    IssueSlug::IDENTICAL_TRANSLATION
                );
            }
        }

        return $findings;
    }

    private function hasUnprotectedIdenticalValue(
        Translation $translation,
        IdenticalTranslationPolicy $policy
    ): bool {
        $original = $translation->getOriginal();
        $value    = $translation->getTranslation();

        if (
            $value !== ''
            && $value === $original
            && !$policy->isProtected($original)
            && !$policy->isLinkOnly($original)
        ) {
            return true;
        }

        if (!$translation->hasPlural()) {
            return false;
        }

        $plural = $translation->getPlural();
        if (
            $plural === null
            || $plural === ''
            || $policy->isProtected($plural)
            || $policy->isLinkOnly($plural)
        ) {
            return false;
        }

        foreach ($translation->getPluralTranslations() as $pluralValue) {
            if ($pluralValue !== '' && $pluralValue === $plural) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Translation[] $translations
     * @return string[]
     */
    private static function msgidLinesForReport(array $translations): array
    {
        $lines = [];
        foreach ($translations as $translation) {
            $context = $translation->getContext();
            $msgid   = $translation->getOriginal();
            $lines[] = ($context !== null && $context !== '' ? '[' . $context . '] ' : '') . $msgid;
        }

        return $lines;
    }

    private static function shorten(string $value): string
    {
        if (strlen($value) <= 80) {
            return $value;
        }

        return substr($value, 0, 77) . '...';
    }
}
