<?php

/**
 * Runs selected audit checks and prints a summary.
 *
 * @package PublishPress\Translations\Audit
 */

namespace PublishPress\Translations\Audit;

use PublishPress\Translations\Audit\Checks\EmptyTranslationCheck;
use PublishPress\Translations\Audit\Checks\FuzzyTranslationCheck;
use PublishPress\Translations\Audit\Checks\PoVersionCheck;
use PublishPress\Translations\Audit\Checks\PotMismatchCheck;
use PublishPress\Translations\Audit\Checks\SourceI18nCheck;
use PublishPress\Translations\Audit\Checks\TextChangeCheck;
use PublishPress\Translations\Audit\Checks\TranslationCountCheck;
use PublishPress\Translations\Output;

final class Auditor
{
    /** @var string */
    private $pluginRoot;

    /** @var string */
    private $languagesDir;

    /** @var string[] */
    private $targetLanguages;

    /** @var Output */
    private $output;

    /** @var string|null */
    private $apiKey;

    /** @var string|null */
    private $pluginVersion;

    /** @var string */
    private $pluginDisplayName;

    /** @var AuditOptions */
    private $options;

    /**
     * @param string[] $targetLanguages
     */
    public function __construct(
        string $pluginRoot,
        string $languagesDir,
        array $targetLanguages,
        Output $output,
        ?string $apiKey,
        ?string $pluginVersion,
        string $pluginDisplayName,
        AuditOptions $options
    ) {
        $this->pluginRoot         = $pluginRoot;
        $this->languagesDir       = $languagesDir;
        $this->targetLanguages    = $targetLanguages;
        $this->output             = $output;
        $this->apiKey             = $apiKey;
        $this->pluginVersion      = $pluginVersion;
        $this->pluginDisplayName  = $pluginDisplayName;
        $this->options            = $options;
    }

    public function run(): bool
    {
        $ctx = new AuditContext(
            $this->pluginRoot,
            $this->languagesDir,
            $this->targetLanguages,
            $this->output,
            $this->apiKey,
            $this->pluginVersion,
            $this->pluginDisplayName,
            $this->options,
            'HEAD'
        );

        $checks = [
            new TextChangeCheck(),
            new EmptyTranslationCheck(),
            new FuzzyTranslationCheck(),
            new PotMismatchCheck(),
            new PoVersionCheck(),
            new SourceI18nCheck(),
            new TranslationCountCheck(),
        ];

        $allFindings = [];
        $idx         = 0;
        $total       = 0;
        foreach ($checks as $c) {
            if (!$this->options->shouldRun($c->id())) {
                continue;
            }
            $total++;
        }

        if ($total === 0) {
            $this->output->line('No audit checks selected (--audit-only filter empty or invalid).');

            return true;
        }

        foreach ($checks as $c) {
            if (!$this->options->shouldRun($c->id())) {
                continue;
            }
            $idx++;
            $this->output->phase('Audit ' . $idx . '/' . $total . ': ' . $c->title());
            $found = $c->run($ctx);
            foreach ($found as $f) {
                $allFindings[] = $f;
                $this->printFinding($f);
            }
        }

        if ($allFindings === []) {
            $this->output->line('No findings.');
        }

        $this->output->separator();

        if ($this->options->usesReportFiles()) {
            $passed = !self::shouldFail($allFindings);
            $dir    = $this->options->reportOutputDir($this->pluginRoot);
            AuditReportWriter::writeFiles(
                $allFindings,
                $this->options->reportFormats(),
                $dir,
                $this->pluginDisplayName,
                $this->pluginVersion,
                $passed,
                $this->output,
                $this->options->only()
            );
        }

        return !self::shouldFail($allFindings);
    }

    /**
     * @param AuditFinding[] $findings
     */
    private static function shouldFail(array $findings): bool
    {
        foreach ($findings as $f) {
            if ($f->severity === 'error') {
                return true;
            }
            if ($f->actionTaken === 'quit') {
                return true;
            }
            if ($f->actionTaken !== null && strpos($f->actionTaken, 'revert failed') !== false) {
                return true;
            }
        }

        return false;
    }

    private function printFinding(AuditFinding $f): void
    {
        $loc = $f->file !== '' ? $f->file : '(n/a)';
        if ($f->language !== '') {
            $loc .= ' [' . $f->language . ']';
        }

        $line = '[' . $f->severity . '] ' . $f->resolvedIssueSlug() . ' ' . $loc . ' — ' . $f->message;
        if ($f->actionTaken !== null && $f->actionTaken !== '') {
            $line .= ' [' . $f->actionTaken . ']';
        }

        if ($f->severity === 'warning') {
            $this->output->warning($line);
        } elseif ($f->severity === 'error') {
            $this->output->warning($line);
        } else {
            $this->output->line($line);
        }
    }
}
