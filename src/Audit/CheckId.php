<?php

/**
 * Stable identifiers for audit checks (CLI --audit-only, filtering).
 *
 * @package PublishPress\Translations\Audit
 */

namespace PublishPress\Translations\Audit;

final class CheckId
{
    public const TEXT_CHANGE = 'text';

    public const EMPTY_TRANSLATION = 'empty';

    public const FUZZY_TRANSLATION = 'fuzzy';

    public const POT_MISMATCH = 'pot';

    public const PO_VERSION = 'version';

    public const SOURCE_I18N = 'source-i18n';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::TEXT_CHANGE,
            self::EMPTY_TRANSLATION,
            self::FUZZY_TRANSLATION,
            self::POT_MISMATCH,
            self::PO_VERSION,
            self::SOURCE_I18N,
        ];
    }

    public static function isValid(string $id): bool
    {
        return in_array($id, self::all(), true);
    }

    /**
     * Short label for reports / UI (English).
     */
    public static function label(string $id): string
    {
        $map = [
            self::TEXT_CHANGE         => 'Text change (AI worthiness)',
            self::EMPTY_TRANSLATION   => 'Empty translations',
            self::FUZZY_TRANSLATION    => 'Fuzzy translations',
            self::POT_MISMATCH        => 'POT vs PO mismatch',
            self::PO_VERSION          => 'PO header',
            self::SOURCE_I18N         => 'Source strings vs POT',
        ];

        return $map[$id] ?? $id;
    }
}
