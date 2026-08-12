<?php

namespace WPMCP\Compliance;

/**
 * One located violation. Every finding carries file:line evidence; a finding
 * that cannot point at a file uses the plugin-relative path it is about (for
 * example readme.txt) with line 0.
 */
final class Finding
{
    private string $rule_id = '';
    private string $guideline = '';
    private string $severity = Severity::BLOCKER;

    public function __construct(
        private string $file,
        private int $line,
        private string $message,
        private string $evidence = '',
        private ?string $severity_override = null
    ) {
    }

    /**
     * Called by the runner once the owning rule and the active profile are
     * known. Rules never set these themselves.
     */
    public function bind(Rule $rule, string $severity): self
    {
        $bound = clone $this;
        $bound->rule_id = $rule->id();
        $bound->guideline = $rule->guideline();
        $bound->severity = $severity;
        return $bound;
    }

    public function rule_id(): string
    {
        return $this->rule_id;
    }

    public function guideline(): string
    {
        return $this->guideline;
    }

    public function severity(): string
    {
        return $this->severity;
    }

    public function severity_override(): ?string
    {
        return $this->severity_override;
    }

    public function file(): string
    {
        return $this->file;
    }

    public function line(): int
    {
        return $this->line;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function evidence(): string
    {
        return $this->evidence;
    }

    /**
     * "path/to/file.php:12", or just the path when the finding is file-wide.
     */
    public function location(): string
    {
        return $this->line > 0 ? $this->file . ':' . $this->line : $this->file;
    }

    public function to_array(): array
    {
        return [
            'rule' => $this->rule_id,
            'guideline' => $this->guideline,
            'severity' => $this->severity,
            'file' => $this->file,
            'line' => $this->line,
            'location' => $this->location(),
            'message' => $this->message,
            'evidence' => $this->evidence,
        ];
    }
}
