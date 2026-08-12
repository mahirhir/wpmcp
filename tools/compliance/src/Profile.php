<?php

namespace WPMCP\Compliance;

use InvalidArgumentException;

/**
 * A profile is the answer to "compliant with what?".
 *
 * wporg-free   the cut submitted to the WordPress.org directory. Strict:
 *              paid gating, licensing code and guarded execution sites are
 *              blockers, because guideline 5 says shipped-but-locked
 *              functionality is prohibited and the two exec sites must not be
 *              in a directory build at all.
 *
 * distribution the full self-hosted build sold off-directory. Lenient about
 *              everything wp.org-specific (licensing, readme conformance,
 *              packaging hygiene) and unchanged about everything that is a
 *              defect regardless of where the zip comes from: escaping,
 *              nonces, capabilities, obfuscation, dishonest privacy copy.
 */
final class Profile
{
    public const WPORG_FREE = 'wporg-free';
    public const DISTRIBUTION = 'distribution';

    /**
     * @param array<string,?string> $severity_overrides rule id => severity, or null to mute
     * @param array<string,mixed>   $options
     */
    private function __construct(
        private string $name,
        private string $description,
        private array $severity_overrides,
        private array $options
    ) {
    }

    /**
     * @return string[]
     */
    public static function names(): array
    {
        return [self::WPORG_FREE, self::DISTRIBUTION];
    }

    public static function named(string $name): self
    {
        switch ($name) {
            case self::WPORG_FREE:
                return self::wporg_free();
            case self::DISTRIBUTION:
                return self::distribution();
        }
        throw new InvalidArgumentException(
            sprintf('unknown profile "%s", expected one of: %s', $name, implode(', ', self::names()))
        );
    }

    /**
     * A profile the caller defines: same engine, different policy. Used by
     * the tests, and available to anyone who needs a house standard stricter
     * or looser than the two shipped profiles.
     *
     * @param array<string,?string> $severity_overrides rule id => severity, null to mute
     * @param array<string,mixed>   $options
     */
    public static function custom(
        string $name,
        string $description,
        array $severity_overrides = [],
        array $options = []
    ): self {
        return new self($name, $description, $severity_overrides, $options);
    }

    public static function wporg_free(): self
    {
        return new self(
            self::WPORG_FREE,
            'WordPress.org directory submission (strict)',
            [],
            [
                // The directory build is a packaged artifact, so packaging
                // rules speak at full severity.
                'artifact' => true,
                // Guideline 5 and the woo flavor build gate agree: no
                // execution call site may survive into a directory build.
                'exec_allowlist' => [],
            ]
        );
    }

    public static function distribution(): self
    {
        return new self(
            self::DISTRIBUTION,
            'Self-hosted paid distribution (licensing permitted)',
            [
                'WPORG-05-TRIALWARE' => Severity::BEST_PRACTICE,
                'WPORG-05-QUOTA' => Severity::BEST_PRACTICE,
                'WPORG-06-LICENSING' => Severity::BEST_PRACTICE,
                'WPORG-07-EXTERNAL-SERVICES' => Severity::BEST_PRACTICE,
                'WPORG-08-UPDATER' => Severity::BEST_PRACTICE,
                'WPORG-12-README' => Severity::BEST_PRACTICE,
                'WPORG-17-TRADEMARK' => Severity::REVIEWER_DISCRETION,
                'PCP-FILE-HYGIENE' => Severity::BEST_PRACTICE,
            ],
            [
                'artifact' => false,
                // The two audited, default-off, environment-gated execution
                // sites. Anything else is still a blocker here.
                'exec_allowlist' => [
                    'src/Tools/Code/Php_Snippet_Runner.php' => ['eval'],
                    'src/Tools/Cli/Wp_Cli_Executor.php' => ['proc_open'],
                ],
            ]
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    /**
     * Final severity for a finding, or null when the rule is muted here.
     */
    public function severity_for(Rule $rule, ?string $finding_override = null): ?string
    {
        if (array_key_exists($rule->id(), $this->severity_overrides)) {
            return $this->severity_overrides[$rule->id()];
        }
        return $finding_override ?? $rule->default_severity();
    }

    public function is_muted(Rule $rule): bool
    {
        return array_key_exists($rule->id(), $this->severity_overrides)
            && null === $this->severity_overrides[$rule->id()];
    }

    /**
     * @return mixed
     */
    public function option(string $key, $default = null)
    {
        return $this->options[$key] ?? $default;
    }

    public function is_artifact_scan(): bool
    {
        return (bool) $this->option('artifact', false);
    }

    /**
     * True when $construct is an audited exception at $relative_path.
     */
    public function allows_exec(string $relative_path, string $construct): bool
    {
        $allowlist = (array) $this->option('exec_allowlist', []);
        $allowed = $allowlist[$relative_path] ?? [];
        return in_array(strtolower($construct), array_map('strtolower', (array) $allowed), true);
    }

    /**
     * @param array<string,mixed> $options
     */
    public function with_options(array $options): self
    {
        return new self($this->name, $this->description, $this->severity_overrides, $options + $this->options);
    }
}
