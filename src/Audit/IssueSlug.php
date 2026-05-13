<?php

/**
 * Stable machine-readable identifiers for individual audit findings.
 *
 * @package PublishPress\Translations\Audit
 */

namespace PublishPress\Translations\Audit;

final class IssueSlug
{
    public const EMPTY_TRANSLATION = 'empty-translation';

    public const FUZZY_TRANSLATION = 'fuzzy-translation';

    public const ORPHAN_MSGID = 'orphan-msgid';

    public const MISSED_MSGID = 'missed-msgid';

    public const NO_POT_IN_LANGUAGES = 'no-pot-in-languages';

    public const POT_PARSE_ERROR = 'pot-parse-error';

    public const PO_PARSE_ERROR = 'po-parse-error';

    public const PO_PARSE_WARNING = 'po-parse-warning';

    public const PROJECT_ID_VERSION_MISSING = 'project-id-version-missing';

    public const PROJECT_ID_VERSION_UNPARSEABLE = 'project-id-version-unparseable';

    public const PROJECT_ID_VERSION_NEWER_THAN_PLUGIN = 'project-id-version-newer-than-plugin';

    public const PROJECT_ID_VERSION_OUTDATED = 'project-id-version-outdated';

    public const PO_VERSION_PLUGIN_UNKNOWN = 'po-version-plugin-unknown';

    public const TEXT_CHANGE_NO_GIT = 'text-change-no-git';

    public const TEXT_CHANGE_NO_CHANGED_PO = 'text-change-no-changed-po';

    public const TEXT_CHANGE_MISSING_AT_BASE = 'text-change-missing-at-base';

    public const TRANSLATION_AI_UNJUDGED = 'translation-ai-unjudged';

    public const TRANSLATION_AI_COST_CAP = 'translation-ai-cost-cap';

    public const TRANSLATION_CHANGE_WORTHY = 'translation-change-worthy';

    public const TRANSLATION_CHANGE_NOT_WORTHY = 'translation-change-not-worthy';

    public const TRANSLATION_CHANGE_QUIT = 'translation-change-quit';

    public const TRANSLATION_CHANGE_KEPT = 'translation-change-kept';

    public const TRANSLATION_CHANGE_REVERTED = 'translation-change-reverted';

    public const TRANSLATION_CHANGE_REVERT_FAILED = 'translation-change-revert-failed';

    /** When no more specific slug was set (avoid for POT mismatch rows). */
    public const POT_MISMATCH_GENERIC = 'pot-mismatch';

    public const SOURCE_STRING_MISSING_FROM_POT = 'source-string-missing-from-pot';

    public const SOURCE_I18N_SUMMARY = 'source-i18n-summary';

    public const JS_PARSE_ERROR = 'js-parse-error';

    public const POT_STRING_COUNT = 'pot-string-count';

    public const TRANSLATION_COUNT_SUMMARY = 'translation-count-summary';

    public static function fallbackForCheckId(string $checkId): string
    {
        switch ($checkId) {
            case CheckId::EMPTY_TRANSLATION:
                return self::EMPTY_TRANSLATION;
            case CheckId::FUZZY_TRANSLATION:
                return self::FUZZY_TRANSLATION;
            case CheckId::POT_MISMATCH:
                return self::POT_MISMATCH_GENERIC;
            case CheckId::TEXT_CHANGE:
                return 'text-change';
            case CheckId::PO_VERSION:
                return 'po-header';
            case CheckId::SOURCE_I18N:
                return self::SOURCE_STRING_MISSING_FROM_POT;
            case CheckId::TRANSLATION_COUNT:
                return self::TRANSLATION_COUNT_SUMMARY;
            default:
                return 'audit-issue';
        }
    }
}
