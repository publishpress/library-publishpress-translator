<?php

/**
 * Writes translation audit findings to txt / ansi / html files.
 *
 * @package PublishPress\Translations\Audit
 */

namespace PublishPress\Translations\Audit;

use PublishPress\Translations\Audit\Report\AuditReportRenderContext;
use PublishPress\Translations\Audit\Report\AuditReportRendererFactory;
use PublishPress\Translations\Output;

final class AuditReportWriter
{
    private const FILE_BASENAME = 'translation-audit';

    /**
     * @param AuditFinding[] $findings
     * @param string[]       $formats           AuditReportFormat::* list
     * @param string[]       $enabledCheckIds   CheckId values that ran (--audit-only)
     *
     * @return string[] absolute paths written
     */
    public static function writeFiles(
        array $findings,
        array $formats,
        string $outputDir,
        string $pluginDisplayName,
        ?string $pluginVersion,
        bool $passed,
        Output $output,
        array $enabledCheckIds = []
    ): array {
        $outputDir = rtrim(str_replace('\\', '/', $outputDir), '/');
        if ($outputDir === '') {
            throw new \InvalidArgumentException('audit report directory must not be empty');
        }

        if (!is_dir($outputDir)) {
            if (!@mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
                throw new \RuntimeException("Could not create audit report directory: {$outputDir}");
            }
        }

        $ctx     = new AuditReportRenderContext($pluginDisplayName, $pluginVersion, $passed, $enabledCheckIds);
        $written = [];
        foreach ($formats as $format) {
            $renderer = AuditReportRendererFactory::forFormat($format);
            $body     = $renderer->render($findings, $ctx);
            $ext      = $format === AuditReportFormat::HTML ? 'html' : ($format === AuditReportFormat::ANSI ? 'ansi.txt' : 'txt');
            $path     = $outputDir . '/' . self::FILE_BASENAME . '.' . $ext;
            if (file_put_contents($path, $body) === false) {
                throw new \RuntimeException("Could not write audit report file: {$path}");
            }
            $written[] = $path;
        }

        foreach ($written as $path) {
            $output->line('Audit report written: ' . $path);
        }

        return $written;
    }
}
