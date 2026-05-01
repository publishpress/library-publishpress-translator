<?php

/**
 * Git diff + AI worthiness + optional revert (surgical splice).
 *
 * @package PublishPress\Translations\Audit\Checks
 */

namespace PublishPress\Translations\Audit\Checks;

use PublishPress\Translations\Audit\AuditCheckInterface;
use PublishPress\Translations\Audit\AuditContext;
use PublishPress\Translations\Audit\AuditFinding;
use PublishPress\Translations\Audit\CheckId;
use PublishPress\Translations\Audit\Support\AiWorthinessJudge;
use PublishPress\Translations\Audit\Support\DiffPrefilter;
use PublishPress\Translations\Audit\Support\GitDiff;
use PublishPress\Translations\Audit\Support\InteractivePrompt;
use PublishPress\Translations\Audit\Support\PoEntrySplicer;
use PublishPress\Translations\Audit\Support\PoFile;

final class TextChangeCheck implements AuditCheckInterface
{
    public function id(): string
    {
        return CheckId::TEXT_CHANGE;
    }

    public function title(): string
    {
        return 'Text-change worthiness (git diff vs HEAD)';
    }

    public function run(AuditContext $ctx): array
    {
        $findings = [];
        $git      = new GitDiff($ctx->pluginRoot());

        if (!GitDiff::isAvailable($ctx->pluginRoot())) {
            $findings[] = new AuditFinding(
                $this->id(),
                'info',
                '',
                '',
                'Git not available or not a repository — skipping diff worthiness check.',
                null,
                null,
                null
            );

            return $findings;
        }

        $relPaths = $git->changedPoFiles($ctx->gitBase());
        $langs    = $ctx->targetLanguages();
        if ($langs !== []) {
            $relPaths = array_values(
                array_filter(
                    $relPaths,
                    static function ($p) use ($langs) {
                        foreach ($langs as $lang) {
                            if (substr($p, -strlen($lang . '.po')) === $lang . '.po') {
                                return true;
                            }
                        }

                        return false;
                    }
                )
            );
        }

        if ($relPaths === []) {
            $findings[] = new AuditFinding(
                $this->id(),
                'info',
                '',
                '',
                'No changed .po files in languages/ for this audit scope.',
                null,
                null,
                null
            );

            return $findings;
        }

        $strict  = $ctx->options()->strictPo();
        $judge   = new AiWorthinessJudge();
        $prompt  = new InteractivePrompt();
        $maxCost = $ctx->options()->maxCostUsd();
        $apiKey  = $ctx->apiKey();

        foreach ($relPaths as $rel) {
            $full = $ctx->pluginRoot() . '/' . $rel;
            if (!is_file($full)) {
                continue;
            }

            $locale  = self::localeFromPoPath($rel);
            $headRaw = $git->fileAtRef($rel, $ctx->gitBase());
            if ($headRaw === null) {
                $findings[] = new AuditFinding(
                    $this->id(),
                    'warning',
                    $rel,
                    $locale,
                    'File missing at ' . $ctx->gitBase() . ' (new file?) — cannot diff against HEAD.',
                    null,
                    null,
                    null
                );
                continue;
            }

            try {
                $headPo = PoFile::fromString($headRaw, $strict);
                $workPo = PoFile::fromFile($full, $strict);
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

            $w = $workPo->parseWarning();
            if ($w !== null) {
                $findings[] = new AuditFinding($this->id(), 'warning', $rel, $locale, $w, null, null, null);
            }

            $changes = self::collectChangedEntries($headPo, $workPo);
            if ($changes === []) {
                continue;
            }

            $prompt->resetAcceptAll();
            $batchSize = 20;
            $pending   = [];

            foreach ($changes as $chg) {
                $id = $chg['id'];
                if (DiffPrefilter::isCosmeticOnly($chg['old'], $chg['new'])) {
                    $f = self::applyOne($ctx, $prompt, $full, $rel, $locale, $chg, false, 'pre-filter: cosmetic only');
                    $findings[] = $f;
                    if ($f->actionTaken === 'quit') {
                        return $findings;
                    }
                    continue;
                }

                $pending[$id] = [
                    'msgid' => $chg['msgid'],
                    'old'   => $chg['old'],
                    'new'   => $chg['new'],
                    '_chg'  => $chg,
                ];

                if (count($pending) >= $batchSize) {
                    $quit = self::flushAiBatch(
                        $ctx,
                        $prompt,
                        $findings,
                        $judge,
                        $apiKey,
                        $locale,
                        $full,
                        $rel,
                        $pending,
                        $maxCost
                    );
                    if ($quit) {
                        return $findings;
                    }
                    $pending = [];
                }
            }

            if ($pending !== []) {
                $quit = self::flushAiBatch(
                    $ctx,
                    $prompt,
                    $findings,
                    $judge,
                    $apiKey,
                    $locale,
                    $full,
                    $rel,
                    $pending,
                    $maxCost
                );
                if ($quit) {
                    return $findings;
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<int,AuditFinding>                                                           $findings
     * @param array<string,array{msgid:string,old:string,new:string,_chg:array<string,mixed>}> $pending
     * @return bool true if user quit mid-check
     */
    private static function flushAiBatch(
        AuditContext $ctx,
        InteractivePrompt $prompt,
        array &$findings,
        AiWorthinessJudge $judge,
        ?string $apiKey,
        string $locale,
        string $fullPath,
        string $relPath,
        array &$pending,
        float $maxCost
    ): bool {
        if ($pending === []) {
            return false;
        }

        if ($apiKey === null || $apiKey === '') {
            foreach ($pending as $row) {
                $chg        = $row['_chg'];
                $findings[] = new AuditFinding(
                    CheckId::TEXT_CHANGE,
                    'warning',
                    $relPath,
                    $locale,
                    'OPENAI_API_KEY missing — cannot judge change for msgid: ' . $chg['msgid'],
                    $row['old'],
                    $row['new'],
                    'unjudged (no API key)'
                );
            }
            $pending = [];

            return false;
        }

        if ($judge->cumulativeCostUsd() >= $maxCost) {
            foreach ($pending as $row) {
                $chg        = $row['_chg'];
                $findings[] = new AuditFinding(
                    CheckId::TEXT_CHANGE,
                    'warning',
                    $relPath,
                    $locale,
                    'Cost cap reached — not judged: ' . $chg['msgid'],
                    $row['old'],
                    $row['new'],
                    'unjudged (cost cap)'
                );
            }
            $pending = [];

            return false;
        }

        $batch = [];
        foreach ($pending as $id => $row) {
            $batch[$id] = [
                'msgid' => $row['msgid'],
                'old'   => $row['old'],
                'new'   => $row['new'],
            ];
        }

        $judged = $judge->judgeCached($apiKey, $locale, $batch);

        foreach ($pending as $id => $row) {
            $chg    = $row['_chg'];
            $result = $judged[$id] ?? ['worthy' => true, 'reason' => 'default'];
            $worthy = (bool) $result['worthy'];
            $reason = (string) $result['reason'];

            $f = self::applyOne($ctx, $prompt, $fullPath, $relPath, $locale, $chg, $worthy, $reason);
            $findings[] = $f;
            if ($f->actionTaken === 'quit') {
                $pending = [];

                return true;
            }
        }

        $pending = [];

        return false;
    }

    /**
     * @param array<string,mixed> $chg
     */
    private static function applyOne(
        AuditContext $ctx,
        InteractivePrompt $prompt,
        string $fullPath,
        string $relPath,
        string $locale,
        array $chg,
        bool $worthy,
        string $reason
    ): AuditFinding {
        if ($worthy) {
            return new AuditFinding(
                CheckId::TEXT_CHANGE,
                'info',
                $relPath,
                $locale,
                'Worthy: ' . $reason,
                $chg['old'],
                $chg['new'],
                'kept'
            );
        }

        if ($ctx->isReportOnly()) {
            return new AuditFinding(
                CheckId::TEXT_CHANGE,
                'warning',
                $relPath,
                $locale,
                'Not worth keeping: ' . $reason,
                $chg['old'],
                $chg['new'],
                'none (report mode)'
            );
        }

        $doRevert = $ctx->isAllowEdit();
        if ($ctx->isInteractive()) {
            $act = $prompt->askDiffAction(
                "Not worth keeping ({$relPath} / {$locale}): {$reason}\nmsgid: " . self::shorten($chg['msgid'])
            );
            if ($act === 'view') {
                $ctx->output()->line('--- old msgstr ---');
                $ctx->output()->line($chg['old']);
                $ctx->output()->line('--- new msgstr ---');
                $ctx->output()->line($chg['new']);
                $act = $prompt->askDiffAction('Action?');
            }
            if ($act === 'quit') {
                return new AuditFinding(
                    CheckId::TEXT_CHANGE,
                    'warning',
                    $relPath,
                    $locale,
                    'User quit check: ' . $reason,
                    $chg['old'],
                    $chg['new'],
                    'quit'
                );
            }
            $doRevert = $act === 'revert';
        }

        if (!$doRevert) {
            return new AuditFinding(
                CheckId::TEXT_CHANGE,
                'info',
                $relPath,
                $locale,
                'Kept after review: ' . $reason,
                $chg['old'],
                $chg['new'],
                'kept'
            );
        }

        try {
            PoEntrySplicer::replaceEntryTranslations(
                $fullPath,
                $chg['context'],
                $chg['msgid'],
                $chg['plural'],
                $chg['revertSingular'],
                $chg['revertPluralRest']
            );
            $action = 'reverted';
        } catch (\Throwable $e) {
            $action = 'revert failed: ' . $e->getMessage();
        }

        return new AuditFinding(
            CheckId::TEXT_CHANGE,
            'warning',
            $relPath,
            $locale,
            'Not worth keeping: ' . $reason,
            $chg['old'],
            $chg['new'],
            $action
        );
    }

    private static function shorten(string $s): string
    {
        if (strlen($s) <= 120) {
            return $s;
        }

        return substr($s, 0, 117) . '...';
    }

    private static function localeFromPoPath(string $rel): string
    {
        $base = basename($rel, '.po');
        $p    = strrpos($base, '-');
        if ($p === false) {
            return $base;
        }

        return substr($base, $p + 1);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function collectChangedEntries(PoFile $headPo, PoFile $workPo): array
    {
        $out = [];
        $i   = 0;

        foreach ($workPo->activeTranslations() as $wt) {
            $ht = $headPo->find($wt->getContext(), $wt->getOriginal());
            $old = $ht === null ? '' : PoFile::serializeTranslation($ht);
            $new = PoFile::serializeTranslation($wt);
            if ($old === $new) {
                continue;
            }

            $revertSingular   = $ht === null ? '' : (string) $ht->getTranslation();
            $revertPluralRest = $ht === null ? [] : array_values($ht->getPluralTranslations());

            $out[] = [
                'id'               => 'c' . $i++,
                'context'          => $wt->getContext(),
                'msgid'            => $wt->getOriginal(),
                'plural'           => $wt->getPlural(),
                'old'              => $old,
                'new'              => $new,
                'revertSingular'   => $revertSingular,
                'revertPluralRest' => $revertPluralRest,
            ];
        }

        return $out;
    }
}
