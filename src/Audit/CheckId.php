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
        ];
    }

    public static function isValid(string $id): bool
    {
        return in_array($id, self::all(), true);
    }
}
