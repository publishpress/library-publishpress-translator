<?php

/**
 * PO string encoding / line building (gettext v4 Generators\Po).
 *
 * @package PublishPress\Translations\Audit\Support
 */

namespace PublishPress\Translations\Audit\Support;

use Gettext\Generators\Po as PoGenerator;

final class PoFormat
{
    /**
     * @return string[]
     */
    public static function directiveLines(string $prefix, string $name, string $value): array
    {
        $lines    = [];
        $newLines = explode("\n", $value);
        $total    = count($newLines);

        if ($total === 1) {
            $lines[] = sprintf('%s%s %s', $prefix, $name, PoGenerator::convertString($newLines[0]));

            return $lines;
        }

        $lines[] = sprintf('%s%s ""', $prefix, $name);

        $last = $total - 1;
        foreach ($newLines as $k => $line) {
            if ($k < $last) {
                $line .= "\n";
            }

            $lines[] = PoGenerator::convertString($line);
        }

        return $lines;
    }
}
