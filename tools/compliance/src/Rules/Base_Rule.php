<?php

namespace WPMCP\Compliance\Rules;

use WPMCP\Compliance\Finding;
use WPMCP\Compliance\Rule;
use WPMCP\Compliance\Severity;
use WPMCP\Compliance\Source_File;

/**
 * Shared plumbing. Rules stay declarative: metadata as methods, findings via
 * the two helpers below.
 */
abstract class Base_Rule implements Rule
{
    public function default_severity(): string
    {
        return Severity::BLOCKER;
    }

    protected function finding(
        Source_File $file,
        int $line,
        string $message,
        ?string $severity = null
    ): Finding {
        return new Finding($file->relative_path(), $line, $message, $file->snippet($line), $severity);
    }

    protected function file_finding(
        string $relative_path,
        string $message,
        ?string $severity = null,
        int $line = 0,
        string $evidence = ''
    ): Finding {
        return new Finding($relative_path, $line, $message, $evidence, $severity);
    }
}
